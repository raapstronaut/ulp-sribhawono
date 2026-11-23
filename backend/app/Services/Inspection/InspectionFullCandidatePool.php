<?php

namespace App\Services\Inspection;

use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\ApkukCandidateService;
use App\Support\PenyulangName;

/**
 * Read-only approved INSPEKSI pool (legacy class name) for isolated v6 pilot.
 * Never queries apkuks/SPS. All unsafe or ambiguous targets are held.
 */
final class InspectionFullCandidatePool
{
    public const VERSION = 'V6A_INSPEKSI_FULL_POOL_1';

    /**
     * @return array{by_posko: array<string, array<int, array<string, mixed>>>, held: array<int, array<string, mixed>>, total: int, eligible: int}
     */
    public function evaluate(): array
    {
        $map = SectionPoskoMap::load();
        $active = WorkOrderApkukTarget::query()
            ->whereNotNull('active_target_key')
            ->pluck('active_target_key')
            ->flip()
            ->all();

        // Exclude pending, rejected and non-POSKO findings regardless of legacy type.
        $findings = InspectionFinding::query()
            ->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->where('suggested_team_type', WorkOrder::TEAM_POSKO)
            ->with('inspection.penyulang')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        // A pole with multiple still-unassigned approved INSPEKSI findings is ambiguous,
        // even if one of its findings has an unknown/unsafe Section mapping.
        // Count BEFORE Section–Posko resolution; never silently select the other record.
        $unassignedPerPole = [];
        foreach ($findings as $finding) {
            $inspection = $finding->inspection;
            $penyulang = $inspection?->penyulang;
            $pole = ApkukCandidateService::normalizePole($finding->apkuk_no_tiang);
            if ($finding->follow_up_work_order_id !== null
                || in_array($pole, ['', '-', 'DUMMY'], true)
                || (int) $finding->apkuk_target_quantity <= 0
                || $penyulang === null || ! $penyulang->is_active
                || ! filled($penyulang->kode_penyulang)) {
                continue;
            }
            $key = ApkukCandidateService::targetKey($penyulang->kode_penyulang, $pole);
            $unassignedPerPole[$key] = ($unassignedPerPole[$key] ?? 0) + 1;
        }

        $held = [];
        $prepared = [];
        foreach ($findings as $finding) {
            $inspection = $finding->inspection;
            $penyulang = $inspection?->penyulang;
            $pole = ApkukCandidateService::normalizePole($finding->apkuk_no_tiang);
            $base = [
                'finding_id' => $finding->getKey(),
                'inspection_id' => $inspection?->getKey(),
                'no_tiang' => $pole,
                'section' => $inspection?->section_name,
                'penyulang' => $penyulang?->nama_penyulang,
            ];
            $reason = null;
            if ($finding->follow_up_work_order_id !== null) {
                $reason = 'TEMUAN_SUDAH_DIPAKAI';
            } elseif (in_array($pole, ['', '-', 'DUMMY'], true)) {
                $reason = 'NO_TIANG_TIDAK_VALID';
            } elseif ((int) $finding->apkuk_target_quantity <= 0) {
                $reason = 'TARGET_TIDAK_VALID';
            } elseif ($penyulang === null || ! $penyulang->is_active || ! filled($penyulang->kode_penyulang)) {
                $reason = 'PENYULANG_TIDAK_AKTIF';
            }
            if ($reason !== null) {
                $held[] = $base + ['reason' => $reason];
                continue;
            }

            $key = ApkukCandidateService::targetKey($penyulang->kode_penyulang, $pole);
            if (($unassignedPerPole[$key] ?? 0) > 1) {
                $held[] = $base + ['reason' => 'TEMUAN_GANDA_SATU_TIANG', 'target_key' => $key];
                continue;
            }
            if (isset($active[$key])) {
                $held[] = $base + ['reason' => 'WO_AKTIF', 'target_key' => $key];
                continue;
            }
            $mapped = $map->resolve(PenyulangName::canonical($penyulang->kode_penyulang), $inspection->section_name);
            if ($mapped['posko'] === null) {
                $held[] = $base + ['reason' => $mapped['reason'], 'target_key' => $key];
                continue;
            }

            $prepared[] = $base + [
                'finding' => $finding,
                'inspection' => $inspection,
                'penyulang_model' => $penyulang,
                'posko' => $mapped['posko'],
                'target_key' => $key,
                'section' => $mapped['section'],
                'quantity' => (int) $finding->apkuk_target_quantity,
            ];
        }

        $byPosko = [];
        foreach ($prepared as $item) {
            $byPosko[$item['posko']->getKey()][] = $item;
        }

        // Existing baseline policy: highest need first, then oldest observation, then UUID.
        foreach ($byPosko as &$candidates) {
            usort($candidates, static function (array $a, array $b): int {
                return ($b['quantity'] <=> $a['quantity'])
                    ?: (strcmp((string) ($a['finding']->occurred_at ?? $a['inspection']->visited_at), (string) ($b['finding']->occurred_at ?? $b['inspection']->visited_at)))
                    ?: strcmp($a['finding_id'], $b['finding_id']);
            });
        }
        unset($candidates);

        return [
            'by_posko' => $byPosko,
            'held' => $held,
            'total' => $findings->count(),
            'eligible' => array_sum(array_map('count', $byPosko)),
        ];
    }
}
