<?php

namespace App\Services\Pasila;

use App\Models\AuditLog;
use App\Models\PasilaSession;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\User;
use App\Services\Apkuk\ApkukSyncLock;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Audit\AuditLogger;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Membuka sesi PASILA shift yang sedang berjalan: satu sesi per Posko aktif, sekali per shift.
 *
 * - Idempoten: dipanggil ulang (retry, scheduler terlambat atau berulang) hanya membuat sesi Posko
 *   yang belum ada. Dua proses bersamaan diserialkan kunci advisory per shift; unique
 *   (shift_instance_id, posko_id) menjadi pengaman terakhir.
 * - Hanya shift berjalan; sesi tidak dibuat mundur untuk shift yang sudah lewat. Keterlambatan
 *   tercatat dari opened_at terhadap awal shift.
 * - Section direkomendasikan dari Section terverifikasi milik Posko (SectionPoskoMap). Posko tanpa
 *   Section terverifikasi tetap mendapat sesi berstatus menunggu penentuan Section.
 * - Tidak membaca atau mengubah WO APKUK, kuota, maupun generator B2; tidak membuat temuan atau P0.
 * - Fail-closed: hanya berjalan bila PASILA_OPENER_ENABLED=true (default nonaktif).
 */
final class PasilaShiftOpener
{
    /** Ruang kunci advisory dua-int pembuka PASILA (terpisah dari kunci kuota APKUK 720_261_029). */
    public const LOCK_NAMESPACE = 720_261_030;

    public function __construct(
        private readonly ShiftService $shifts,
        private readonly PasilaSectionStrategy $strategy,
        private readonly AuditLogger $audit,
    ) {}

    public function strategy(): PasilaSectionStrategy
    {
        return $this->strategy;
    }

    public function blockedReason(): ?string
    {
        return config('pasila.opener.enabled')
            ? null
            : 'Pembuka sesi PASILA otomatis nonaktif (PASILA_OPENER_ENABLED=false). Tidak ada sesi yang dibuat di lingkungan ini.';
    }

    /**
     * @return array{status: string, message: ?string, shift: ?ShiftInstance, created: array<int, PasilaSession>, existing: int}
     */
    public function openCurrent(string $trigger, ?User $actor = null, ?CarbonImmutable $now = null): array
    {
        if ($blocked = $this->blockedReason()) {
            return ['status' => 'REFUSED', 'message' => $blocked, 'shift' => null, 'created' => [], 'existing' => 0];
        }

        $now ??= CarbonImmutable::now();
        $current = $this->shifts->instanceAt($now);
        $shift = ShiftInstance::ensure($this->shifts, $current['operational_date'], $current['code']);

        try {
            [$created, $existing] = DB::transaction(fn () => $this->open($shift, $trigger, $actor, $now));
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'REFUSED', 'message' => 'Sesi PASILA shift ini baru saja dibuka proses lain; tidak ada sesi ganda yang dibuat.', 'shift' => $shift, 'created' => [], 'existing' => 0];
        } catch (QueryException $e) {
            // 55P03 = lock_not_available: pembuka lain untuk shift ini belum selesai.
            if ($e->getCode() === '55P03') {
                return ['status' => 'REFUSED', 'message' => 'Pembukaan sesi PASILA shift ini sedang diproses lebih dari '.ApkukSyncLock::LOCK_TIMEOUT.'. Tidak ada perubahan; coba lagi.', 'shift' => $shift, 'created' => [], 'existing' => 0];
            }
            throw $e;
        }

        return ['status' => 'COMPLETED', 'message' => null, 'shift' => $shift, 'created' => $created, 'existing' => $existing];
    }

    /**
     * @return array{0: array<int, PasilaSession>, 1: int}
     */
    private function open(ShiftInstance $shift, string $trigger, ?User $actor, CarbonImmutable $now): array
    {
        self::lock($shift);

        $existing = PasilaSession::query()->where('shift_instance_id', $shift->getKey())->pluck('posko_id')->all();
        $poskos = Posko::query()->where('is_active', true)->whereNotIn('id', $existing)->orderBy('nama_posko')->get();
        if ($poskos->isEmpty()) {
            return [[], count($existing)];
        }

        $map = SectionPoskoMap::load();
        $created = [];
        foreach ($poskos as $posko) {
            $sections = $map->sectionsForPosko($posko->getKey());
            $pick = $sections === [] ? null : $this->strategy->recommend($posko, $sections, $shift);
            $entry = $pick['entry'] ?? null;

            $session = PasilaSession::create([
                'code' => self::code($shift, $posko),
                'shift_instance_id' => $shift->getKey(),
                'posko_id' => $posko->getKey(),
                'status' => PasilaSession::STATUS_DIBUKA,
                'section_status' => $entry ? PasilaSession::SECTION_REKOMENDASI : PasilaSession::SECTION_MENUNGGU,
                'penyulang_id' => $entry ? $entry['penyulang_model']->getKey() : null,
                'section_key' => $entry['key'] ?? null,
                'section_name' => $entry['section'] ?? null,
                'section_hold_reason' => $entry ? null : PasilaSession::HOLD_TIDAK_ADA_SECTION,
                'recommendation' => [
                    'strategy' => $this->strategy->name(),
                    'version' => $this->strategy->version(),
                    'is_machine_learning' => false,
                    'reference' => 'SECTION_PIC_RAW',
                    'considered' => count($sections),
                    // Rekomendasi awal tetap tercatat walau Admin kemudian mengganti Section sesi.
                    'recommended_section' => $entry['section'] ?? null,
                    'recommended_penyulang' => $entry['penyulang'] ?? null,
                    'reasons' => $pick['reasons'] ?? [],
                ],
                // Disimpan UTC (zona aplikasi), sama seperti ShiftInstance, apa pun zona $now pemanggil.
                'opened_at' => $now->utc(),
                'opened_trigger' => $trigger,
                'opened_by_user_id' => $actor?->getKey(),
            ]);

            $this->audit->record('pasila_session', $session->getKey(), $session->code, AuditLog::ACTION_CREATED, null, [
                'shift' => $shift->operational_date->toDateString().'/'.$shift->shift_code,
                'posko' => $posko->nama_posko,
                'section_status' => $session->section_status,
                'penyulang' => $entry['penyulang'] ?? null,
                'section' => $session->section_name,
                'strategy' => $this->strategy->name().' '.$this->strategy->version(),
                'trigger' => $trigger,
            ], $actor);

            $created[] = $session;
        }

        return [$created, count($existing)];
    }

    /**
     * Kode tampilan unik: PSL-{tanggal operasional}-{shift}-{kode Posko tanpa spasi/tanda baca}.
     */
    public static function code(ShiftInstance $shift, Posko $posko): string
    {
        return 'PSL-'.$shift->operational_date->format('Ymd').'-'.$shift->shift_code.'-'.preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $posko->kode_posko));
    }

    /**
     * Kunci transaksi pembukaan satu shift (semua Posko), dilepas saat commit/rollback. Tidak
     * berurutan dengan kunci APKUK karena pembuka tidak mengambil kunci lain. SQLite (test) tidak
     * mengenal kunci ini; transaksinya serial.
     */
    private static function lock(ShiftInstance $shift): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '".ApkukSyncLock::LOCK_TIMEOUT."'");
        DB::select('select pg_advisory_xact_lock(?::int, hashtext(?))', [self::LOCK_NAMESPACE, $shift->operational_date->toDateString().'|'.$shift->shift_code]);
    }
}
