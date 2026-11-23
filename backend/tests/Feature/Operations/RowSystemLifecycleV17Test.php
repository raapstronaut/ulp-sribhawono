<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use App\Services\Row\RowAdminPlanDispatchPilot;
use App\Services\Row\RowInspectionPlanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** V17 lifecycle regression: generated ROW WO through existing mobile/admin workflow, SQLite :memory: only. */
final class RowSystemLifecycleV17Test extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 11, 0, 0, 'Asia/Jakarta'));
        config(['row.pilot_generation_enabled' => true]);
    }

    public function test_system_row_work_order_reaches_mobile_team_and_finishes_after_admin_review(): void
    {
        $inspection = Inspection::create([
            'code' => 'INS-ROW-V17-LIFECYCLE',
            'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'visited_at' => now()->subDay(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
        ]);
        $finding = InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'POHON_DEKAT',
            'description' => 'Pohon dekat jaringan - pengujian V17',
            'latitude' => -5.477771,
            'longitude' => 105.477771,
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ]);
        $this->assertTrue(app(RowInspectionPlanService::class)->save(
            $finding, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_PANGKAS
        ));
        $result = app(RowAdminPlanDispatchPilot::class)->generateForIsolatedTest('2026-11-23');
        $this->assertSame(1, $result['count']);
        $wo = WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)->sole();
        $this->assertSame(WorkOrder::SOURCE_SYSTEM, $wo->source);
        $this->assertSame((string) $wo->getKey(), (string) $finding->fresh()->follow_up_work_order_id);
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->status);
        $this->assertNull($wo->due_at, 'Jatuh tempo ROW tidak boleh dikarang.');
        $this->assertNotNull($wo->locationTarget->active_target_key);

        $account = $this->fieldAccount($this->rowTeam, 'row_v17_team');
        $this->actingAs($account, 'sanctum');
        $this->getJson('/api/v1/mobile/work-feed')
            ->assertOk()->assertJsonPath('data.0.code', $wo->code)
            ->assertJsonPath('data.0.source', WorkOrder::SOURCE_SYSTEM);
        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")
            ->assertOk()->assertJsonPath('data.status', WorkOrderStatus::Dikerjakan->value);
        $person = $this->rowTeam->personnel()->firstOrFail();
        $this->post("/api/v1/mobile/work-orders/{$wo->id}/reports", [
            'client_request_id' => 'row-v17-photo-report-1',
            'report_type' => WorkOrderReport::TYPE_PEKERJAAN_SELESAI,
            'latitude' => -5.477771,
            'longitude' => 105.477771,
            'personnel_ids' => [$person->getKey()],
            'photos_before' => [$this->photo()],
            'photos_after' => [$this->photo()],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.work_order.status', WorkOrderStatus::MenungguVerifikasi->value);
        auth()->forgetGuards();

        $report = $wo->reports()->sole();
        $this->actingAs($this->admin)
            ->post(route('admin.reviews.reports.approve', $report))
            ->assertSessionHasNoErrors();
        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Selesai, $wo->status);
        $this->assertNull($wo->locationTarget->fresh()->active_target_key);
        $this->assertGreaterThanOrEqual(4, $wo->statusLogs()->count());
        $this->assertSame(1, DB::table('work_orders')->count(), 'Status selesai tidak membuat WO tambahan.');
    }
}
