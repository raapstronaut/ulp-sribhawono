<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Row\RowAdminPlanDispatchPilot;
use App\Services\Row\RowInspectionPlanService;
use App\Services\Row\RowPlanningReadOnlyPreview;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** V13 real WO end-to-end simulation, exclusively SQLite :memory:. */
final class RowAdminPlanDispatchPilotTest extends OperationsTestCase
{
    private const DATE = '2026-11-23';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 11, 0, 0, 'Asia/Jakarta'));
        config(['row.pilot_generation_enabled' => true]);
    }

    public function test_admin_saved_plans_generate_ten_per_team_with_audited_provenance_and_idempotence(): void
    {
        $other = FieldTeam::create(['code' => 'ROW-V13-B', 'name' => 'ROW V13 kedua', 'role' => 'ROW']);
        foreach (range(1, 12) as $i) {
            $this->plan($this->finding(), $this->rowTeam->getKey(), WorkOrder::TYPE_PANGKAS);
        }
        foreach (range(1, 3) as $i) {
            $this->plan($this->finding(), $other->getKey(), WorkOrder::TYPE_TEBANG);
        }
        WorkOrder::create([
            'code' => 'WO-ROW-V13-MANUAL', 'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_RABAS, 'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan, 'field_team_id' => $this->rowTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(), 'work_date' => self::DATE,
        ]);

        $preview = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertSame(13, $preview['eligible_for_preview']);
        $this->assertSame(2, $preview['held_by_reason']['KUOTA_HARIAN_PENUH']);
        $this->assertSame(1, DB::table('work_orders')->count());

        $run = app(RowAdminPlanDispatchPilot::class)->generateForIsolatedTest(self::DATE);
        $this->assertSame(13, $run['count']);
        $this->assertCount(13, $run['created']);
        $this->assertSame(13, WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)
            ->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(10, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)
            ->where('field_team_id', $this->rowTeam->getKey())->count());
        $this->assertSame(3, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)
            ->where('field_team_id', $other->getKey())->count());
        $this->assertSame(1, WorkOrder::query()->where('source', WorkOrder::SOURCE_ADMIN_MANUAL)->count());
        $this->assertSame(13, InspectionFinding::query()->whereNotNull('follow_up_work_order_id')->count());
        $this->assertSame(13, DB::table('work_order_location_targets')->count());
        $this->assertSame(15, DB::table('row_inspection_plan_logs')->count());

        $sample = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->firstOrFail();
        $payload = $sample->statusLogs()->firstOrFail()->payload;
        $this->assertSame('INSPEKSI', $payload['origin']);
        $this->assertSame('EXPLICIT_TEST_PLAN', $payload['assignment_origin']);
        $this->assertNull($sample->due_at, 'Jadwal ROW belum boleh ditebak.');

        $again = app(RowAdminPlanDispatchPilot::class)->generateForIsolatedTest(self::DATE);
        $this->assertSame(0, $again['count']);
        $this->assertSame(13, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(0, DB::table('apkuk_generation_runs')->count());
        $this->assertSame(0, DB::table('apkuk_shift_allocations')->count());
    }

    public function test_unapproved_missing_admin_plan_or_duplicate_coordinates_never_create_work_order(): void
    {
        $this->finding();
        $pending = $this->finding(['validation_status' => InspectionFinding::VALIDATION_MENUNGGU]);
        $this->plan($pending, $this->rowTeam->getKey(), WorkOrder::TYPE_RABAS, false);
        $a = $this->finding(['latitude' => -5.388888, 'longitude' => 105.388888]);
        $b = $this->finding(['latitude' => -5.388888, 'longitude' => 105.388888]);
        $this->plan($a, $this->rowTeam->getKey(), WorkOrder::TYPE_RABAS);
        $this->plan($b, $this->rowTeam->getKey(), WorkOrder::TYPE_PANGKAS);

        $preview = app(RowPlanningReadOnlyPreview::class)->preview(self::DATE);
        $this->assertSame(0, $preview['eligible_for_preview']);
        $this->assertSame(2, $preview['held_by_reason']['TEMUAN_GANDA_SATU_TITIK']);
        $this->assertSame(1, $preview['held_by_reason']['BUTUH_JENIS_DAN_TIM_ROW']);
        $run = app(RowAdminPlanDispatchPilot::class)->generateForIsolatedTest(self::DATE);
        $this->assertSame(0, $run['count']);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_explicit_flag_and_in_memory_database_are_required(): void
    {
        $finding = $this->finding();
        $this->plan($finding, $this->rowTeam->getKey(), WorkOrder::TYPE_TEBANG);
        config(['row.pilot_generation_enabled' => false]);
        try {
            app(RowAdminPlanDispatchPilot::class)->generateForIsolatedTest(self::DATE);
            $this->fail('Dispatch must refuse disabled test flag.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('V13 ROW DISPATCH PILOT DITOLAK', $e->getMessage());
        }
        config(['row.pilot_generation_enabled' => true]);
        config(['database.connections.sqlite.database' => 'local.sqlite']);
        try {
            app(RowAdminPlanDispatchPilot::class)->generateForIsolatedTest(self::DATE);
            $this->fail('Dispatch must refuse non-memory SQLite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('V13 ROW DISPATCH PILOT DITOLAK', $e->getMessage());
        } finally {
            config(['database.connections.sqlite.database' => ':memory:']);
        }
        $this->assertSame(0, WorkOrder::query()->count());
    }

    private function plan(InspectionFinding $finding, string $teamId, string $type, bool $save = true): void
    {
        if ($save) {
            $this->assertTrue(app(RowInspectionPlanService::class)->save($finding, $this->admin, $teamId, $type));
        }
    }

    private function finding(array $overrides = []): InspectionFinding
    {
        $this->sequence++;
        $i = $this->sequence;
        $inspection = Inspection::create([
            'code' => 'INS-ROW-V13-'.$i,
            'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'visited_at' => now()->subDay(), 'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
        ]);
        return InspectionFinding::create(array_merge([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'POHON_DEKAT',
            'description' => 'Temuan vegetasi ROW v13-'.$i,
            'latitude' => -5.45 - ($i / 1000),
            'longitude' => 105.45 + ($i / 1000),
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ], $overrides));
    }
}
