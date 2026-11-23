<?php

namespace App\Services\Inspection;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\ApkukCandidateService;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderCodeGenerator;
use App\Services\Work\WorkflowConflictException;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Manual POSKO APKUK WO from an approved finding in the single INSPEKSI form.
 * Never invent a related SPS apkuk_id. The target snapshot uses the existing
 * APKUK WO shape so the mobile report and active-target guard can reuse it.
 * This service does NOT implement the six SYSTEM WOs/shift generator.
 */
final class InspectionApkukWorkOrderService
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly ShiftService $shifts,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly WorkOrderLifecycleService $lifecycle,
    ) {}

    /** @param array{posko_id: string, field_team_id?: ?string, work_date: string, shift_code: string, notes?: ?string} $input */
    public function createManual(InspectionFinding $finding, User $admin, array $input): WorkOrder
    {
        // Fail closed if the new LOCAL schema was not migrated yet.
        if (! Schema::hasColumn('work_order_apkuk_targets', 'inspection_finding_id')) {
            throw ValidationException::withMessages(['finding' => 'Skema target INSPEKSI untuk WO POSKO belum dipasang. Hubungi Admin sistem.']);
        }
        $now = CarbonImmutable::now();
        $date = (string) $input['work_date'];
        $shift = (string) $input['shift_code'];

        if (! in_array($shift, $this->shifts->codes(), true)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift tidak dikenal.']);
        }
        $window = $this->shifts->window($date, $shift);
        if ($window['end']->lte($now)) {
            throw ValidationException::withMessages(['shift_code' => 'Shift yang dipilih sudah berakhir.']);
        }

        $posko = Posko::query()->whereKey($input['posko_id'])->where('is_active', true)->first();
        if ($posko === null) {
            throw ValidationException::withMessages(['posko_id' => 'POSKO tidak ditemukan atau tidak aktif.']);
        }
        $team = null;
        if (filled($input['field_team_id'] ?? null)) {
            $team = FieldTeam::query()->whereKey($input['field_team_id'])->where('is_active', true)->first();
            if ($team === null || $team->role !== WorkOrder::TEAM_POSKO || $team->posko_id !== $posko->getKey()) {
                throw ValidationException::withMessages(['field_team_id' => 'Tim pelaksana harus tim aktif milik POSKO yang dipilih.']);
            }
        }

        for ($attempt = 1; $attempt <= self::CODE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($finding, $admin, $input, $date, $shift, $now, $window, $posko, $team): WorkOrder {
                    // Same lock order as legacy generator/manual: shift quota lock first.
                    // Manual WO skips assertSlotAvailable; legacy generator quota still needs v5 revision.
                    ApkukShiftQuota::lock($date, $shift);
                    $locked = InspectionFinding::query()->lockForUpdate()->findOrFail($finding->getKey());
                    if ($locked->validation_status !== InspectionFinding::VALIDATION_DISETUJUI
                        || $locked->follow_up_work_order_id !== null) {
                        throw new WorkflowConflictException('Temuan harus sudah disetujui dan belum memiliki WO.');
                    }
                    $inspection = $locked->inspection()->with('penyulang')->firstOrFail();
                    if (! $locked->isApprovedPoskoApkukTarget()) {
                        throw ValidationException::withMessages(['finding' => 'WO POSKO membutuhkan temuan APKUK valid dari INSPEKSI yang sudah disetujui.']);
                    }
                    $penyulang = $inspection->penyulang;
                    if ($penyulang === null || ! $penyulang->is_active || ! filled($penyulang->kode_penyulang)) {
                        throw ValidationException::withMessages(['finding' => 'Identitas penyulang inspeksi belum terverifikasi.']);
                    }

                    $pole = ApkukCandidateService::normalizePole($locked->apkuk_no_tiang);
                    $targetKey = ApkukCandidateService::targetKey($penyulang->kode_penyulang, $pole);
                    $active = WorkOrderApkukTarget::query()->where('active_target_key', $targetKey)->with('workOrder:id,code')->first();
                    if ($active !== null) {
                        throw ValidationException::withMessages(['finding' => "Tiang sudah memiliki WO APKUK aktif ({$active->workOrder?->code})."]);
                    }

                    $qty = (int) $locked->apkuk_target_quantity;
                    $wo = WorkOrder::create([
                        'code' => $this->codes->next(WorkOrderCodeGenerator::KIND_APKUK, $now),
                        'team_type' => WorkOrder::TEAM_POSKO,
                        'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
                        'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
                        'status' => WorkOrderStatus::Ditugaskan,
                        'posko_id' => $posko->getKey(),
                        'field_team_id' => $team?->getKey(),
                        'penyulang_id' => $penyulang->getKey(),
                        'section_name' => $inspection->section_name,
                        'work_date' => $date,
                        'shift_code' => $shift,
                        'scheduled_start_at' => $window['start']->setTimezone(config('app.timezone')),
                        'due_at' => $window['end']->setTimezone(config('app.timezone')),
                        'notes' => filled($input['notes'] ?? null) ? trim($input['notes']) : $locked->description,
                        'created_by_user_id' => $admin->getKey(),
                    ]);

                    WorkOrderApkukTarget::create([
                        'work_order_id' => $wo->getKey(),
                        'apkuk_id' => null, // NOT A FABRICATED SPS RECORD
                        'inspection_finding_id' => $locked->getKey(),
                        'target_key' => $targetKey,
                        'active_target_key' => $targetKey,
                        'no_tiang' => $pole,
                        'penyulang_source' => $penyulang->kode_penyulang,
                        'section_source' => $inspection->section_name,
                        'source_report_date' => $inspection->visited_at?->setTimezone(ShiftService::TIMEZONE)->toDateString(),
                        'source_status' => 'INSPEKSI_DISETUJUI',
                        'source_terpasang_bh' => null,
                        'source_kurang_bh' => $qty,
                        'latitude' => $locked->latitude,
                        'longitude' => $locked->longitude,
                        'target_quantity' => $qty,
                    ]);

                    $locked->forceFill(['follow_up_work_order_id' => $wo->getKey()])->save();
                    $this->lifecycle->log($wo, WorkOrderStatusLog::ACTION_CREATED, null, WorkOrderStatus::Ditugaskan, $admin, null, [
                        'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
                        'origin' => 'INSPEKSI',
                        'inspection_id' => $inspection->getKey(),
                        'inspection_finding_id' => $locked->getKey(),
                        'apkuk_id' => null,
                        'target_key' => $targetKey,
                        'target_quantity' => $qty,
                        'posko_id' => $posko->getKey(),
                        'work_date' => $date,
                        'shift_code' => $shift,
                        'assignment_by' => 'ADMIN_AFTER_APPROVAL',
                    ]);

                    return $wo;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Another transaction won the target/finding claim or WO code race.
                $linked = InspectionFinding::query()->find($finding->getKey());
                if ($linked?->follow_up_work_order_id !== null) {
                    throw new WorkflowConflictException('Temuan sudah memiliki WO.');
                }
                if ($attempt === self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new WorkflowConflictException('WO tidak dapat dibuat setelah batas percobaan.');
    }
}
