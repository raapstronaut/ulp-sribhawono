<?php

namespace Tests\Feature\Operations;

use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Row\RowDailyGeneratorPilot;
use App\Services\Row\RowInspectionPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class RowInspectionPlanTest extends OperationsTestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 11, 0, 0, 'Asia/Jakarta'));
        config(['row.pilot_generation_enabled' => true]);
    }

    public function test_admin_can_assign_approved_row_finding_without_creating_wo_and_pilot_uses_explicit_plan(): void
    {
        $finding = $this->finding();
        $this->actingAs($this->admin)->get(route('admin.row.plan'))
            ->assertOk()->assertSee('Perencanaan WO ROW dari INSPEKSI')
            ->assertSee($finding->finding_code)->assertSee('Perencanaan tidak menerbitkan WO.', false);
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $finding), [
            'field_team_id' => $this->rowTeam->getKey(),
            'work_type' => WorkOrder::TYPE_PANGKAS,
            'work_date' => '2026-11-23',
        ])->assertRedirect(route('admin.row.plan', ['work_date' => '2026-11-23']));
        $this->assertSame(1, DB::table('row_inspection_plans')->count());
        $this->assertSame(1, DB::table('row_inspection_plan_logs')->count());
        $this->assertSame(0, WorkOrder::query()->count());
        $plan = app(RowInspectionPlanService::class)->assignments();
        $this->assertSame(WorkOrder::TYPE_PANGKAS, $plan[$finding->getKey()]['work_type']);
        $pilotPreview = app(RowDailyGeneratorPilot::class)->preview('2026-11-23', $plan);
        $this->assertSame(1, $pilotPreview['rows'][0]['new']);
        $this->assertSame(9, $pilotPreview['rows'][0]['shortfall']);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_same_plan_is_idempotent_and_change_keeps_append_only_audit(): void
    {
        $finding = $this->finding();
        $service = app(RowInspectionPlanService::class);
        $this->assertTrue($service->save($finding, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_RABAS));
        $this->assertFalse($service->save($finding, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_RABAS));
        $this->assertTrue($service->save($finding, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_TEBANG));
        $this->assertSame(1, DB::table('row_inspection_plans')->count());
        $this->assertSame(2, DB::table('row_inspection_plan_logs')->count());
        $last = DB::table('row_inspection_plan_logs')->orderByDesc('id')->first();
        $this->assertSame(WorkOrder::TYPE_RABAS, $last->previous_work_type);
        $this->assertSame(WorkOrder::TYPE_TEBANG, $last->work_type);
    }

    public function test_reject_pending_wrong_role_inactive_team_invalid_type_and_existing_wo(): void
    {
        $pending = $this->finding(['validation_status' => InspectionFinding::VALIDATION_MENUNGGU]);
        $wrong = $this->finding(['suggested_team_type' => WorkOrder::TEAM_HARSUS]);
        $valid = $this->finding();
        $data = ['field_team_id' => $this->rowTeam->getKey(), 'work_type' => WorkOrder::TYPE_RABAS];
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $pending), $data)->assertSessionHasErrors('row_plan');
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $wrong), $data)->assertSessionHasErrors('row_plan');
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $valid), ['field_team_id' => $this->harsusTeam->getKey(), 'work_type' => WorkOrder::TYPE_RABAS])->assertSessionHasErrors('field_team_id');
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $valid), ['field_team_id' => $this->rowTeam->getKey(), 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK])->assertSessionHasErrors('work_type');
        $this->rowTeam->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $valid), $data)->assertSessionHasErrors('field_team_id');
        $this->rowTeam->forceFill(['is_active' => true])->save();
        $order = WorkOrder::create([
            'code' => 'WO-ROW-V10-MANUAL', 'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_RABAS, 'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => \App\Enums\WorkOrderStatus::Ditugaskan,
            'field_team_id' => $this->rowTeam->getKey(), 'penyulang_id' => $this->penyulang->getKey(),
            'work_date' => '2026-11-23',
        ]);
        $valid->forceFill(['follow_up_work_order_id' => $order->getKey()])->save();
        $this->actingAs($this->admin)->post(route('admin.row.plan.store', $valid), $data)->assertSessionHasErrors('row_plan');
        $this->assertSame(0, DB::table('row_inspection_plans')->count());
        $this->assertSame(0, DB::table('row_inspection_plan_logs')->count());
    }

    public function test_only_admin_may_read_or_write_plan_and_nonlocal_database_fails_closed(): void
    {
        $finding = $this->finding();
        $this->get(route('admin.row.plan'))->assertRedirect(route('login'));
        $field = $this->fieldAccount($this->rowTeam, 'ROW-V10');
        $this->actingAs($field)->get(route('admin.row.plan'))->assertForbidden();
        $this->actingAs($field)->post(route('admin.row.plan.store', $finding), [
            'field_team_id' => $this->rowTeam->getKey(), 'work_type' => WorkOrder::TYPE_RABAS,
        ])->assertForbidden();
        $this->actingAs($this->admin);
        config(['database.connections.sqlite.database' => 'local.sqlite']);
        $this->get(route('admin.row.plan'))->assertNotFound();
        $this->post(route('admin.row.plan.store', $finding), [
            'field_team_id' => $this->rowTeam->getKey(), 'work_type' => WorkOrder::TYPE_RABAS,
        ])->assertNotFound();
        config(['database.connections.sqlite.database' => ':memory:']);
        $this->assertSame(0, DB::table('row_inspection_plans')->count());
    }

    public function test_manual_row_work_order_does_not_use_or_reduce_system_daily_quota(): void
    {
        $this->finding();
        WorkOrder::create([
            'code' => 'WO-ROW-V10-MANUAL', 'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_PANGKAS, 'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => \App\Enums\WorkOrderStatus::Ditugaskan,
            'field_team_id' => $this->rowTeam->getKey(), 'penyulang_id' => $this->penyulang->getKey(),
            'work_date' => '2026-11-23',
        ]);
        $this->actingAs($this->admin)->get(route('admin.row.plan', ['work_date' => '2026-11-23']))
            ->assertOk()->assertSee('0 / 10')->assertSee('WO manual');
    }

    private function finding(array $overrides = []): InspectionFinding
    {
        $this->sequence++;
        $i = $this->sequence;
        $inspection = Inspection::create([
            'code' => 'INS-ROW-V10-'.$i, 'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(), 'visited_at' => now()->subDay(),
            'has_finding' => true, 'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
        ]);
        return InspectionFinding::create(array_merge([
            'inspection_id' => $inspection->getKey(), 'finding_code' => 'POHON_DEKAT',
            'description' => 'Temuan vegetasi ROW v10-'.$i,
            'latitude' => -5.41 - ($i / 1000), 'longitude' => 105.42 + ($i / 1000),
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ], $overrides));
    }
}
