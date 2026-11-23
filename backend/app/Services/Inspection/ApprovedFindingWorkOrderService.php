<?php

namespace App\Services\Inspection;

use App\Models\InspectionFinding;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Work\LocationTargetResolver;
use App\Services\Work\TeamWorkOrderCreator;
use App\Services\Work\WorkflowConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat WO HARSUS/ROW setelah temuan INSPEKSI di-ACC tanpa WO pada saat ACC.
 *
 * Pengaman: admin route, lock baris temuan, ACC wajib, tautan WO tunggal,
 * validasi jenis pekerjaan, dan dedupe target aktif dari TeamWorkOrderCreator.
 * POSKO tidak dicampurkan: target APKUK legacy masih wajib apkuk_id dari SPS.
 * Pembuatan WO baru dan pemasangan tautan temuan dilakukan atomik.
 */
final class ApprovedFindingWorkOrderService
{
    public function __construct(
        private readonly TeamWorkOrderCreator $creator,
        private readonly LocationTargetResolver $targets,
        private readonly \App\Services\Work\RowPointConflictGuard $rowPointGuard,
    ) {}

    public static function isUnlinkedApproved(InspectionFinding $finding): bool
    {
        return $finding->validation_status === InspectionFinding::VALIDATION_DISETUJUI
            && $finding->follow_up_work_order_id === null;
    }

    public static function supportsPlan(string $teamType, string $workType): bool
    {
        return in_array($teamType, [WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW], true)
            && in_array($workType, WorkOrder::WORK_TYPES_BY_TEAM[$teamType] ?? [], true);
    }

    /**
     * @param array{team_type: string, work_type: string, work_date: string, due_date: string, field_team_id?: ?string, notes?: ?string} $input
     */
    public function create(InspectionFinding $finding, User $admin, array $input): WorkOrder
    {
        return DB::transaction(function () use ($finding, $admin, $input): WorkOrder {
            // Acquire the shared ROW advisory lock BEFORE finding row locks.
            // Otherwise generator (advisory -> finding) versus manual
            // (finding -> advisory) can deadlock. SQLite tests use no-op.
            if (($input['team_type'] ?? null) === WorkOrder::TEAM_ROW) {
                $this->rowPointGuard->lock();
            }
            $finding = InspectionFinding::query()->lockForUpdate()->findOrFail($finding->getKey());

            if (! self::isUnlinkedApproved($finding)) {
                throw new WorkflowConflictException('Temuan harus disetujui dan belum mempunyai WO tindak lanjut.');
            }

            $teamType = (string) ($input['team_type'] ?? '');
            $workType = (string) ($input['work_type'] ?? '');
            if (! self::supportsPlan($teamType, $workType)) {
                throw ValidationException::withMessages(['follow_up' => 'Jenis WO hanya dapat berupa pekerjaan HARSUS atau ROW yang sesuai.']);
            }

            // Pertahankan aturan lama HARSUS: harus merujuk aset keypoint terverifikasi.
            // Temuan teknis berkoordinat saja perlu rancangan verifikasi aset lebih lanjut.
            if ($teamType === WorkOrder::TEAM_HARSUS && $finding->keypoint_id === null) {
                throw ValidationException::withMessages(['follow_up' => 'WO HARSUS perlu keypoint aset jaringan yang valid.']);
            }
            if (filled($finding->suggested_team_type) && $finding->suggested_team_type !== $teamType) {
                throw ValidationException::withMessages(['follow_up' => 'Tim WO harus sesuai tim tujuan yang dipilih pada temuan INSPEKSI.']);
            }

            $inspection = $finding->inspection()->with('penyulang')->firstOrFail();
            $penyulang = $inspection->penyulang;
            if ($penyulang === null || ! $penyulang->is_active) {
                throw ValidationException::withMessages(['follow_up' => 'Penyulang inspeksi tidak tersedia atau tidak aktif.']);
            }

            $workOrder = $this->creator->create($admin, [
                'team_type' => $teamType,
                'work_type' => $workType,
                'penyulang_id' => $penyulang->getKey(),
                'field_team_id' => $input['field_team_id'] ?? null,
                'section_name' => $inspection->section_name,
                'work_date' => $input['work_date'],
                'due_date' => $input['due_date'],
                'notes' => filled($input['notes'] ?? null) ? $input['notes'] : $finding->description,
            ], $penyulang, $this->targets->fromFinding($finding, $penyulang), WorkOrder::SOURCE_ADMIN_MANUAL, null, [
                'inspection_id' => $inspection->getKey(),
                'inspection_finding_id' => $finding->getKey(),
                'suggested_team_type' => $finding->suggested_team_type,
                'assigned_team_type' => $teamType,
                'assignment_by' => 'ADMIN_AFTER_APPROVAL',
            ]);

            $finding->forceFill(['follow_up_work_order_id' => $workOrder->getKey()])->save();

            return $workOrder;
        });
    }
}
