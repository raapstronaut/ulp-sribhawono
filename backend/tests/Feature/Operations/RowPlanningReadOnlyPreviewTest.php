<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Row\RowDailyGeneratorPilot;
use App\Services\Row\RowInspectionPlanService;
use App\Services\Row\RowPlanningReadOnlyPreview;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only V12 preview on isolated SQLite :memory:. Local PostgreSQL is never used by tests. */
final class RowPlanningReadOnlyPreviewTest extends OperationsTestCase
{
    private const DATE = '2026-11-23';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 11, 0, 0, 'Asia/Jakarta'));
        config(['row.pilot_generation_enabled' => false]);
    }

    public function test_admin_preview_reads_saved_plan_without_creating_work_order_or_log(): void
    {
        $finding = $this->finding();
        app(RowInspectionPlanService::class)->save(
            $finding, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_PANGKAS
        );

        $this->actingAs($this->admin)->get(route('admin.row.plan'))
            ->assertOk()->assertSee('Lihat pratinjau v12');
        $this->actingAs($this->admin)->get(route('admin.row.plan.preview', ['work_date' => self::DATE]))
            ->assertOk()->assertSee('Pratinjau generator ROW')
            ->assertSee('Temuan vegetasi ROW v12')->assertSee('PANGKAS');

        $report = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['generation_enabled']);
        $this->assertSame(1, $report['persisted_admin_assignments']);
        $this->assertSame(1, $this->row($report)['new']);
        $this->assertSame(9, $this->row($report)['shortfall']);
        $this->assertSame(0, DB::table('work_orders')->count());
        $this->assertSame(1, DB::table('row_inspection_plan_logs')->count());
        $this->assertSame(0, DB::table('work_order_location_targets')->count());
        $this->assertSame(0, DB::table('apkuk_generation_runs')->count());
    }

    public function test_unassigned_approved_row_is_held_and_other_teams_or_pending_are_not_candidates(): void
    {
        $this->finding();
        $this->finding(['validation_status' => InspectionFinding::VALIDATION_MENUNGGU]);
        $this->finding(['suggested_team_type' => WorkOrder::TEAM_HARSUS]);
        $report = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertSame(1, $report['approved_row_findings']);
        $this->assertSame(0, $this->row($report)['new']);
        $this->assertSame(1, $report['held_by_reason']['BUTUH_JENIS_DAN_TIM_ROW']);
        $this->assertSame(0, DB::table('work_orders')->count());
    }

    public function test_daily_ten_cap_and_manual_wo_separate_and_overflow_explained(): void
    {
        $second = FieldTeam::create(['code' => 'ROW-V12-B', 'name' => 'ROW Kedua V12', 'role' => 'ROW']);
        foreach (range(1, 12) as $i) {
            $f = $this->finding();
            app(RowInspectionPlanService::class)->save(
                $f, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_RABAS
            );
        }
        $f2 = $this->finding();
        app(RowInspectionPlanService::class)->save($f2, $this->admin, $second->getKey(), WorkOrder::TYPE_TEBANG);

        $this->workOrder('V12-MAN', WorkOrder::SOURCE_ADMIN_MANUAL);
        $this->workOrder('V12-SYS-1', WorkOrder::SOURCE_SYSTEM);
        $this->workOrder('V12-SYS-2', WorkOrder::SOURCE_SYSTEM);

        $report = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertSame(2, $this->row($report)['system_existing']);
        $this->assertSame(1, $this->row($report)['manual']);
        $this->assertSame(8, $this->row($report)['new']);
        $this->assertSame(0, $this->row($report)['shortfall']);
        $this->assertSame(4, $report['held_by_reason']['KUOTA_HARIAN_PENUH']);
        $this->assertSame(1, $this->row($report, $second->getKey())['new']);
        $this->assertSame(3, DB::table('work_orders')->count());
    }

    public function test_duplicate_coordinates_are_held_and_plan_does_not_erase_held_reason(): void
    {
        $a = $this->finding(['latitude' => -5.300123, 'longitude' => 105.300123]);
        $b = $this->finding(['latitude' => -5.300123, 'longitude' => 105.300123]);
        foreach ([$a, $b] as $finding) {
            app(RowInspectionPlanService::class)->save(
                $finding, $this->admin, $this->rowTeam->getKey(), WorkOrder::TYPE_PANGKAS
            );
        }
        $report = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertSame(2, $report['held_by_reason']['TEMUAN_GANDA_SATU_TITIK']);
        $this->assertSame(0, $this->row($report)['new']);
        $this->assertSame(0, DB::table('work_orders')->count());
    }

    public function test_route_requires_admin_and_non_memory_test_db_is_rejected_without_write(): void
    {
        $this->get(route('admin.row.plan.preview'))->assertRedirect(route('login'));
        $field = $this->fieldAccount($this->rowTeam, 'ROW-V12');
        $this->actingAs($field)->get(route('admin.row.plan.preview'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.row.plan.preview', ['work_date' => 'bad']))
            ->assertSessionHasErrors('work_date');
        config(['database.connections.sqlite.database' => 'local.sqlite']);
        $this->get(route('admin.row.plan.preview'))->assertNotFound();
        try {
            app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
            $this->fail('Preview must reject non-memory SQLite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ROW V12 DITOLAK', $e->getMessage());
        } finally {
            config(['database.connections.sqlite.database' => ':memory:']);
        }
        $this->assertSame(0, DB::table('work_orders')->count());
    }

    public function test_pilot_generate_remains_locked_and_preview_matches_saved_assignment(): void
    {
        $finding = $this->finding();
        app(RowInspectionPlanService::class)->save($finding, $this->admin,
            $this->rowTeam->getKey(), WorkOrder::TYPE_TEBANG);
        $assignments = app(RowInspectionPlanService::class)->assignments();
        $pilot = app(RowDailyGeneratorPilot::class);
        $pilotResult = $pilot->preview(self::DATE, $assignments);
        $liveResult = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertSame($pilotResult['rows'], $liveResult['rows']);
        $this->assertSame($pilotResult['held'], $liveResult['held']);
        try {
            $pilot->generateForIsolatedTest(self::DATE, $assignments);
            $this->fail('Pilot generator must remain disabled by default.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PILOT ROW V9 DITOLAK', $e->getMessage());
        }
        $this->assertSame(0, DB::table('work_orders')->count());
    }

    private function row(array $report, ?string $teamId = null): array
    {
        $id = $teamId ?? $this->rowTeam->getKey();
        foreach ($report['rows'] as $row) {
            if ($row['field_team_id'] === $id) {
                return $row;
            }
        }
        $this->fail('Tim ROW tidak ditemukan.');
    }

    private function workOrder(string $code, string $source): void
    {
        WorkOrder::create([
            'code' => 'WO-'.$code,
            'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_RABAS,
            'source' => $source,
            'status' => WorkOrderStatus::Ditugaskan,
            'field_team_id' => $this->rowTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'work_date' => self::DATE,
        ]);
    }

    private function finding(array $overrides = []): InspectionFinding
    {
        $this->sequence++;
        $i = $this->sequence;
        $inspection = Inspection::create([
            'code' => 'INS-ROW-V12-'.$i,
            'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'visited_at' => now()->subDay(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
        ]);
        return InspectionFinding::create(array_merge([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'POHON_DEKAT',
            'description' => 'Temuan vegetasi ROW v12-'.$i,
            'latitude' => -5.35 - ($i / 1000),
            'longitude' => 105.35 + ($i / 1000),
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ], $overrides));
    }
}
