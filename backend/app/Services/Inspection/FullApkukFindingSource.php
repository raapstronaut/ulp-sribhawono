<?php

namespace App\Services\Inspection;

use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * Pool bukti awal dari INSPEKSI (nama class dipertahankan untuk kompatibilitas), read-only.
 * JANGAN oper ke createSystem(): bentuk target berbeda dari ApkukCandidate SPS.
 * Belum cek duplicate target WO, mapping Section-Posko, jadwal, maupun ML.
 */
final class FullApkukFindingSource
{
    /** @return Collection<int, InspectionFinding> */
    public function approved(): Collection
    {
        return InspectionFinding::query()
            ->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->where('suggested_team_type', WorkOrder::TEAM_POSKO)
            ->whereNotNull('apkuk_no_tiang')
            ->where('apkuk_target_quantity', '>', 0)
            ->with(['inspection.penyulang'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (InspectionFinding $finding) => $finding->isApprovedPoskoApkukTarget())
            ->values();
    }
}
