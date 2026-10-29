<?php

namespace App\Services\Apkuk\Generation;

use App\Enums\WorkOrderStatus;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Services\Apkuk\ApkukSyncLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kuota enam WO PEMASANGAN_APKUK per Posko per shift (keputusan pengguna 29 Okt 2026).
 *
 * - Limpahan sah: WO yang jadwalnya saat ini adalah shift ini karena reschedule DISETUJUI
 *   (approveReschedule memindahkan work_date/shift_code WO yang sama; ID dan histori tetap).
 *   WO yang sekadar belum selesai tetap berada di shift asalnya, sehingga tidak terhitung.
 * - WO lain yang sudah terjadwal di shift ini (manual Admin, atau hasil generate sebelumnya) juga
 *   menempati slot: generator hanya mengisi slot yang masih kosong. WO DIBATALKAN tidak menempati slot.
 * - Setiap WO dihitung sekali (per ID), berapa pun riwayat reschedule-nya.
 * - Hanya limpahan sah yang boleh membuat shift melebihi kuota. Generator, WO manual Admin, dan
 *   pemindahan jadwal oleh Admin memeriksa slot di bawah lock() (assertSlotAvailable).
 */
final class ApkukShiftQuota
{
    public const TARGET_PER_SHIFT = 6;

    /** Ruang kunci advisory dua-int kuota (terpisah dari kunci bigint generator dan ApkukSyncLock). */
    public const LOCK_NAMESPACE = 720_261_029;

    /**
     * Kunci transaksi kuota satu shift (tanggal operasional + kode, semua Posko), dilepas saat
     * commit/rollback. Generator dan setiap penempatan WO APKUK biasa ke shift memegangnya selama
     * membaca slot dan menulis WO, sehingga hitungan slot selalu dibaca setelah penulis lain shift
     * itu selesai.
     *
     * Urutan kunci: kunci generator → kunci kuota → ApkukSyncLock::shared. Pemegang kunci kuota tidak
     * pernah menunggu kunci baris WO yang sudah ada, jadi pengubah jadwal boleh mengambilnya setelah
     * mengunci baris WO-nya. Reschedule disetujui (limpahan sah) tidak mengambil kunci ini karena
     * limpahan memang boleh melebihi kuota. SQLite (test) tidak mengenal kunci ini; transaksinya serial.
     */
    public static function lock(string $operationalDate, string $shiftCode): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '".ApkukSyncLock::LOCK_TIMEOUT."'");
        try {
            DB::select('select pg_advisory_xact_lock(?::int, hashtext(?))', [self::LOCK_NAMESPACE, $operationalDate.'|'.$shiftCode]);
        } catch (QueryException $e) {
            // 55P03 = lock_not_available: generator atau penempatan WO lain untuk shift ini belum selesai.
            if ($e->getCode() === '55P03') {
                throw ValidationException::withMessages(['shift_code' => 'Kuota WO APKUK shift ini sedang diproses (generator atau WO lain) lebih dari '.ApkukSyncLock::LOCK_TIMEOUT.'. Tidak ada perubahan; coba lagi.']);
            }
            throw $e;
        }
    }

    /**
     * Menolak WO APKUK biasa (buat manual atau pindah jadwal oleh Admin) bila Posko + shift sudah
     * terisi TARGET_PER_SHIFT WO. Tidak ada override; kelebihan hanya boleh berasal dari limpahan sah.
     * Dipanggil setelah lock() di transaksi yang sama.
     */
    public function assertSlotAvailable(Posko $posko, string $operationalDate, string $shiftCode): void
    {
        $scheduled = $this->scheduled($operationalDate, $shiftCode, $posko->getKey())[$posko->getKey()] ?? null;
        $carry = $scheduled ? $scheduled['carry_over']->count() : 0;
        $other = $scheduled ? $scheduled['existing']->count() : 0;
        if ($carry + $other < self::TARGET_PER_SHIFT) {
            return;
        }

        throw ValidationException::withMessages(['shift_code' => sprintf(
            'Kuota WO APKUK Posko %s Shift %s hari operasional %s sudah penuh (kuota %d, terisi %d: limpahan sah %d, WO lain %d). WO tidak ditempatkan di shift ini; pilih shift lain atau batalkan WO yang tidak diperlukan.',
            $posko->nama_posko, $shiftCode, CarbonImmutable::parse($operationalDate)->format('d/m/Y'), self::TARGET_PER_SHIFT, $carry + $other, $carry, $other,
        )]);
    }

    /**
     * @return array<string, array{carry_over: Collection<int, WorkOrder>, existing: Collection<int, WorkOrder>}> per posko_id
     */
    public function scheduled(string $operationalDate, string $shiftCode, ?string $poskoId = null): array
    {
        $workOrders = WorkOrder::query()->apkuk()
            ->whereDate('work_date', $operationalDate)
            ->where('shift_code', $shiftCode)
            ->where('status', '!=', WorkOrderStatus::Dibatalkan->value)
            ->whereNotNull('posko_id')
            ->when($poskoId !== null, fn ($query) => $query->where('posko_id', $poskoId))
            ->with(['reschedules' => fn ($query) => $query->where('status', WorkOrderReschedule::STATUS_DISETUJUI)])
            ->orderBy('code')
            ->get();

        $result = [];
        foreach ($workOrders as $workOrder) {
            $result[$workOrder->posko_id] ??= ['carry_over' => collect(), 'existing' => collect()];
            $result[$workOrder->posko_id][self::isCarryOver($workOrder) ? 'carry_over' : 'existing']->push($workOrder);
        }

        return $result;
    }

    /**
     * Rumus kuota. Tanpa WO lain terjadwal (existing = 0) sama dengan rumus pengguna:
     * new_slots = max(0, 6 − limpahan); shortfall = max(0, 6 − limpahan − baru); overload = max(0, limpahan − 6).
     *
     * @return array{target: int, carry_over: int, existing: int, new_slots: int, new: int, total: int, shortfall: int, overload: int}
     */
    public static function compute(int $carryOver, int $existing, int $newCount): array
    {
        $occupied = $carryOver + $existing;
        $total = $occupied + $newCount;

        return [
            'target' => self::TARGET_PER_SHIFT,
            'carry_over' => $carryOver,
            'existing' => $existing,
            'new_slots' => max(0, self::TARGET_PER_SHIFT - $occupied),
            'new' => $newCount,
            'total' => $total,
            'shortfall' => max(0, self::TARGET_PER_SHIFT - $total),
            'overload' => max(0, $total - self::TARGET_PER_SHIFT),
        ];
    }

    /**
     * Jumlah WO baru yang boleh dibuat: min(slot kosong, kandidat valid).
     */
    public static function newCount(int $carryOver, int $existing, int $eligibleCandidates): int
    {
        return min(max(0, self::TARGET_PER_SHIFT - $carryOver - $existing), max(0, $eligibleCandidates));
    }

    private static function isCarryOver(WorkOrder $workOrder): bool
    {
        $date = $workOrder->work_date?->toDateString();

        return $workOrder->reschedules->contains(fn (WorkOrderReschedule $reschedule) => $reschedule->proposed_work_date?->toDateString() === $date
            && ($reschedule->proposed_shift_code ?? $workOrder->shift_code) === $workOrder->shift_code);
    }
}
