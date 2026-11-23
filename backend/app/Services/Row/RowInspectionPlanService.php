<?php

namespace App\Services\Row;

use App\Models\FieldTeam;
use App\Models\InspectionFinding;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan keputusan Admin untuk temuan INSPEKSI -> ROW.
 * Tidak membuat WorkOrder, mengubah validasi inspeksi, atau mengurangi kuota.
 */
final class RowInspectionPlanService
{
    public function save(InspectionFinding $finding, User $admin, string $teamId, string $type): bool
    {
        if (! $admin->canAccessAdmin()) {
            throw ValidationException::withMessages(['row_plan' => 'Hanya Admin aktif yang boleh menyusun rencana ROW.']);
        }
        if (! in_array($type, WorkOrder::ROW_WORK_TYPES, true)) {
            throw ValidationException::withMessages(['work_type' => 'Jenis pekerjaan harus RABAS, PANGKAS, atau TEBANG.']);
        }

        return DB::transaction(function () use ($finding, $admin, $teamId, $type): bool {
            $finding = InspectionFinding::query()->lockForUpdate()->findOrFail($finding->getKey());
            if ($finding->validation_status !== InspectionFinding::VALIDATION_DISETUJUI
                || $finding->suggested_team_type !== WorkOrder::TEAM_ROW
                || $finding->follow_up_work_order_id !== null) {
                throw ValidationException::withMessages(['row_plan' => 'Temuan harus sudah ACC, memilih ROW, dan belum mempunyai WO.']);
            }
            $team = FieldTeam::query()->whereKey($teamId)->where('role', WorkOrder::TEAM_ROW)
                ->where('is_active', true)->first();
            if ($team === null) {
                throw ValidationException::withMessages(['field_team_id' => 'Tim penerima harus tim ROW aktif.']);
            }
            $before = DB::table('row_inspection_plans')
                ->where('inspection_finding_id', $finding->getKey())->lockForUpdate()->first();
            if ($before !== null && $before->field_team_id === $teamId && $before->work_type === $type) {
                return false;
            }
            $now = now();
            if ($before === null) {
                DB::table('row_inspection_plans')->insert([
                    'inspection_finding_id' => $finding->getKey(),
                    'field_team_id' => $teamId,
                    'work_type' => $type,
                    'planned_by_user_id' => $admin->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('row_inspection_plans')->where('inspection_finding_id', $finding->getKey())->update([
                    'field_team_id' => $teamId,
                    'work_type' => $type,
                    'planned_by_user_id' => $admin->getKey(),
                    'updated_at' => $now,
                ]);
            }
            DB::table('row_inspection_plan_logs')->insert([
                'inspection_finding_id' => $finding->getKey(),
                'actor_user_id' => $admin->getKey(),
                'previous_field_team_id' => $before?->field_team_id,
                'field_team_id' => $teamId,
                'previous_work_type' => $before?->work_type,
                'work_type' => $type,
                'created_at' => $now,
            ]);
            return true;
        }, 3);
    }

    /**
     * Peta penugasan yang secara eksplisit sudah diputuskan Admin, untuk preview
     * PILOT ROW di SQLite :memory:. Tidak pernah membuat WO.
     *
     * @return array<string, array{field_team_id:string,work_type:string}>
     */
    public function assignments(): array
    {
        $out = [];
        $rows = DB::table('row_inspection_plans as p')
            ->join('inspection_findings as f', 'f.id', '=', 'p.inspection_finding_id')
            ->join('field_teams as t', 't.id', '=', 'p.field_team_id')
            ->where('f.validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->where('f.suggested_team_type', WorkOrder::TEAM_ROW)
            ->whereNull('f.follow_up_work_order_id')
            ->where('t.role', WorkOrder::TEAM_ROW)->where('t.is_active', true)
            ->select('p.inspection_finding_id', 'p.field_team_id', 'p.work_type')->get();
        foreach ($rows as $row) {
            if (! in_array($row->work_type, WorkOrder::ROW_WORK_TYPES, true)) {
                continue;
            }
            $out[$row->inspection_finding_id] = [
                'field_team_id' => $row->field_team_id,
                'work_type' => $row->work_type,
            ];
        }
        return $out;
    }
}
