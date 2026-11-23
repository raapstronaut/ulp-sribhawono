<?php

namespace App\Services\Row;

use App\Models\FieldTeam;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Services\Work\LocationTargetResolver;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * V12 shared pure reader: previews the existing V9 pilot decisions without writing.
 * No generator, scheduler, HTTP route, or database mutations exist in this class.
 * PostgreSQL access is restricted to RowPlanningReadOnlyPreview's read-only TX.
 */
final class RowDailyPreviewPlanner
{
    public const DAILY_TARGET_PER_TEAM = 10;

    public function __construct(private readonly LocationTargetResolver $resolver) {}

    /**
     * @param array<string, array{field_team_id?: string, work_type?: string}> $assignments
     * @return array<string, mixed>
     */
    public function preview(string $date, array $assignments = []): array
    {
        $this->assertValidDate($date);

        $teams = FieldTeam::query()->where('role', WorkOrder::TEAM_ROW)
            ->where('is_active', true)->orderBy('code')->orderBy('id')->get();
        $byTeam = [];
        $teamIds = [];
        foreach ($teams as $team) {
            $id = (string) $team->getKey();
            $teamIds[$id] = true;
            $existing = WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)
                ->where('field_team_id', $id)
                ->where('source', WorkOrder::SOURCE_SYSTEM)
                ->whereDate('work_date', $date)->count();
            $manual = WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)
                ->where('field_team_id', $id)
                ->where('source', WorkOrder::SOURCE_ADMIN_MANUAL)
                ->whereDate('work_date', $date)->count();
            $byTeam[$id] = [
                'field_team_id' => $id,
                'team_code' => $team->code,
                'target' => self::DAILY_TARGET_PER_TEAM,
                'system_existing' => $existing,
                'manual' => $manual,
                'planned' => [],
                'new' => 0,
                'shortfall' => max(0, self::DAILY_TARGET_PER_TEAM - $existing),
            ];
        }

        $findings = InspectionFinding::query()
            ->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->where('suggested_team_type', WorkOrder::TEAM_ROW)
            ->with('inspection.penyulang')
            ->orderBy('occurred_at')->orderBy('id')->get();

        // Resolve identity before applying explicit assignment: two unlinked
        // approved findings at the same keypoint/coordinate must both be held.
        $prepared = [];
        $identityCounts = [];
        $held = [];
        foreach ($findings as $finding) {
            $base = ['finding_id' => (string) $finding->getKey(), 'finding_code' => $finding->finding_code];
            if ($finding->follow_up_work_order_id !== null) {
                $held[] = $base + ['reason' => 'SUDAH_MEMILIKI_WO'];
                continue;
            }
            $inspection = $finding->inspection;
            $penyulang = $inspection?->penyulang;
            if ($inspection === null || $penyulang === null || ! $penyulang->is_active) {
                $held[] = $base + ['reason' => 'PENYULANG_TIDAK_AKTIF'];
                continue;
            }
            try {
                $target = $this->resolver->fromFinding($finding, $penyulang);
            } catch (ValidationException $e) {
                $held[] = $base + ['reason' => 'TARGET_TIDAK_VALID'];
                continue;
            }
            if ($target['target_type'] === WorkOrderLocationTarget::TYPE_KEYPOINT) {
                $identity = 'K:'.(string) $target['target_key'];
            } else {
                // INSPEKSI:{finding_uuid} is unique even for identical coordinates.
                // Deduplicate by coordinates as well so the pilot cannot silently
                // create two ROW WOs for a single observation point.
                $identity = 'C:'.$penyulang->getKey().'|'
                    .number_format((float) $target['latitude'], 6, '.', '').'|'
                    .number_format((float) $target['longitude'], 6, '.', '');
            }
            $identityCounts[$identity] = ($identityCounts[$identity] ?? 0) + 1;
            $prepared[] = $base + [
                'finding_id' => (string) $finding->getKey(),
                'inspection_id' => (string) $inspection->getKey(),
                'penyulang_id' => (string) $penyulang->getKey(),
                'section' => $inspection->section_name,
                'description' => $finding->description,
                'target' => $target,
                'identity' => $identity,
            ];
        }

        $allActive = WorkOrderLocationTarget::query()->whereNotNull('active_target_key')
            ->whereHas('workOrder', fn ($q) => $q->where('team_type', WorkOrder::TEAM_ROW))
            ->get(['target_key', 'target_type', 'latitude', 'longitude', 'work_order_id']);
        foreach ($prepared as $candidate) {
            $base = ['finding_id' => $candidate['finding_id'], 'finding_code' => $candidate['finding_code']];
            $target = $candidate['target'];
            if ($identityCounts[$candidate['identity']] > 1) {
                $held[] = $base + ['reason' => 'TEMUAN_GANDA_SATU_TITIK'];
                continue;
            }
            // Block any active ROW WO at the same network asset, independent of
            // work subtype, or same exact coordinate within its feeder.
            $activeCollision = $allActive->contains(function ($active) use ($candidate, $target): bool {
                if ($active->target_key === $target['target_key']) {
                    return true;
                }
                return $target['latitude'] !== null && $target['longitude'] !== null
                    && $active->latitude !== null && $active->longitude !== null
                    && abs((float) $active->latitude - (float) $target['latitude']) < 0.0000005
                    && abs((float) $active->longitude - (float) $target['longitude']) < 0.0000005;
            });
            if ($activeCollision) {
                $held[] = $base + ['reason' => 'WO_ROW_AKTIF_DI_TITIK'];
                continue;
            }
            $assignment = $assignments[$candidate['finding_id']] ?? [];
            $type = (string) ($assignment['work_type'] ?? '');
            $teamId = (string) ($assignment['field_team_id'] ?? '');
            if (! in_array($type, WorkOrder::ROW_WORK_TYPES, true) || ! isset($teamIds[$teamId])) {
                $held[] = $base + ['reason' => 'BUTUH_JENIS_DAN_TIM_ROW'];
                continue;
            }
            $candidate['field_team_id'] = $teamId;
            $candidate['work_type'] = $type;
            $byTeam[$teamId]['planned'][] = $candidate;
        }

        foreach ($byTeam as &$row) {
            $slots = max(0, self::DAILY_TARGET_PER_TEAM - $row['system_existing']);
            // Explicitly report valid candidates outside the remaining daily capacity.
            $overflow = array_slice($row['planned'], $slots);
            foreach ($overflow as $candidate) {
                $held[] = ['finding_id' => $candidate['finding_id'],
                    'finding_code' => $candidate['finding_code'], 'reason' => 'KUOTA_HARIAN_PENUH'];
            }
            $row['planned'] = array_slice($row['planned'], 0, $slots);
            $row['new'] = count($row['planned']);
            $row['shortfall'] = max(0, self::DAILY_TARGET_PER_TEAM - $row['system_existing'] - $row['new']);
        }
        unset($row);

        return [
            'date' => $date,
            'target_per_team' => self::DAILY_TARGET_PER_TEAM,
            'rows' => array_values($byTeam),
            'held' => $held,
            'approved_row_findings' => $findings->count(),
            'strategy' => RowDailyGeneratorPilot::STRATEGY,
            'source' => 'INSPEKSI',
            'read_only' => true,
            'held_by_reason' => collect($held)->countBy('reason')->all(),
            'eligible_for_preview' => array_sum(array_column(array_values($byTeam), 'new')),
        ];
    }

    private function assertValidDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new LogicException('Tanggal ROW tidak valid.');
        }
    }
}
