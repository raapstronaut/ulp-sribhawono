<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderStatusLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan WO PEMASANGAN_APKUK untuk POSKO.
 *
 * Duplikasi dicegah dua lapis: penilaian kandidat (WO_AKTIF) dan unique index
 * work_order_apkuk_targets.active_target_key untuk request yang bersamaan.
 */
class ApkukWorkOrderService
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly ApkukCandidateService $candidates,
        private readonly ShiftService $shifts,
        private readonly WorkOrderLifecycleService $lifecycle,
    ) {}

    /**
     * @param  array{apkuk_id: int|string, posko_id: string, work_date: string, shift_code: string, notes?: string|null}  $input
     */
    public function createManual(User $admin, array $input, ?CarbonImmutable $now = null): WorkOrder
    {
        $now ??= CarbonImmutable::now();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): WorkOrder => $this->create($admin, $input, $now));
            } catch (UniqueConstraintViolationException $e) {
                // Request lain lebih dulu membuat WO aktif untuk tiang yang sama.
                $candidate = $this->candidates->find((int) $input['apkuk_id']);
                if ($candidate?->activeWorkOrderCode !== null) {
                    throw $this->duplicateException($candidate->activeWorkOrderCode);
                }
                // Selain itu kemungkinan bentrok kode WO; ulangi dengan nomor berikutnya.
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array{apkuk_id: int|string, posko_id: string, work_date: string, shift_code: string, notes?: string|null}  $input
     */
    private function create(User $admin, array $input, CarbonImmutable $now): WorkOrder
    {
        $candidate = $this->candidates->find((int) $input['apkuk_id']);
        if ($candidate === null) {
            throw ValidationException::withMessages(['apkuk_id' => 'Data APKUK tidak ditemukan.']);
        }
        if ($candidate->activeWorkOrderCode !== null) {
            throw $this->duplicateException($candidate->activeWorkOrderCode);
        }
        if (! $candidate->isEligible()) {
            throw ValidationException::withMessages([
                'apkuk_id' => 'Lokasi tidak memenuhi syarat WO APKUK: '.implode('; ', $candidate->issueLabels()).'.',
            ]);
        }

        // POSKO wajib dipilih Admin; tidak diturunkan dari penyulang.
        $posko = Posko::query()->whereKey($input['posko_id'])->where('is_active', true)->first();
        if ($posko === null) {
            throw ValidationException::withMessages(['posko_id' => 'POSKO tidak ditemukan atau tidak aktif.']);
        }

        if (! in_array($input['shift_code'], $this->shifts->codes(), true)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift tidak dikenal.']);
        }
        $window = $this->shifts->window($input['work_date'], $input['shift_code']);
        if ($window['end']->lte($now)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift yang dipilih sudah berakhir.']);
        }

        $apkuk = $candidate->apkuk;
        $appTimezone = config('app.timezone');

        $workOrder = WorkOrder::create([
            'code' => $this->nextCode($now),
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan,
            'posko_id' => $posko->getKey(),
            'penyulang_id' => $candidate->penyulang->getKey(),
            'section_name' => $candidate->sectionName,
            'work_date' => $input['work_date'],
            'shift_code' => $input['shift_code'],
            'scheduled_start_at' => $window['start']->setTimezone($appTimezone),
            'due_at' => $window['end']->setTimezone($appTimezone),
            'notes' => filled($input['notes'] ?? null) ? trim($input['notes']) : null,
            'created_by_user_id' => $admin->getKey(),
        ]);

        WorkOrderApkukTarget::create([
            'work_order_id' => $workOrder->getKey(),
            'apkuk_id' => $apkuk->id,
            'target_key' => $candidate->targetKey,
            'active_target_key' => $candidate->targetKey,
            'no_tiang' => $apkuk->no_tiang,
            'penyulang_source' => $apkuk->penyulang,
            'section_source' => $apkuk->section,
            'naik_dari' => $apkuk->naik_dari,
            'source_report_date' => $apkuk->tanggal,
            'source_status' => $apkuk->status,
            'source_terpasang_bh' => $apkuk->terpasang_bh,
            'source_kurang_bh' => $apkuk->kurang_bh,
            'latitude' => $apkuk->latitude,
            'longitude' => $apkuk->longitude,
            'target_quantity' => $candidate->targetQuantity(),
        ]);

        $this->lifecycle->log(
            $workOrder,
            WorkOrderStatusLog::ACTION_CREATED,
            null,
            WorkOrderStatus::Ditugaskan,
            $admin,
            null,
            [
                'apkuk_id' => $apkuk->id,
                'apkuk_source_id' => $apkuk->source_id,
                'target_key' => $candidate->targetKey,
                'target_quantity' => $candidate->targetQuantity(),
                'posko_id' => $posko->getKey(),
                'work_date' => $input['work_date'],
                'shift_code' => $input['shift_code'],
                'warnings' => $candidate->warnings,
            ],
        );

        return $workOrder;
    }

    /**
     * Format: WO-APKUK-YYYYMMDD-NNNN (tanggal pembuatan WIB, nomor urut harian).
     */
    private function nextCode(CarbonImmutable $now): string
    {
        $prefix = 'WO-APKUK-'.$now->setTimezone(ShiftService::TIMEZONE)->format('Ymd').'-';
        $last = WorkOrder::query()->where('code', 'like', $prefix.'%')->max('code');
        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function duplicateException(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'apkuk_id' => "Tiang ini sudah memiliki WO APKUK aktif ({$code}). Selesaikan atau batalkan WO tersebut terlebih dahulu.",
        ]);
    }
}
