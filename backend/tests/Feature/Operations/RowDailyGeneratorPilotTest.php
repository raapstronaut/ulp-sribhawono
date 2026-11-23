<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Services\Row\RowDailyGeneratorPilot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** V9 only: simulated ROW WO in test SQLite memory; no routes/commands/schedulers. */
class RowDailyGeneratorPilotTest extends OperationsTestCase
{
    private const DATE = '2026-11-23';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 11, 0, 0, 'Asia/Jakarta'));
        config(['row.pilot_generation_enabled' => true]);
    }

    public function test_preview_holds_approved_row_findings_until_explicit_work_type_and_team_are_chosen(): void
    {
        $findings = [];
        foreach (range(1, 6) as $i) {
            $findings[] = $this->finding();
        }
        $pilot = app(RowDailyGeneratorPilot::class);
        $before = $pilot->preview(self::DATE);
        $row = $this->row($before, $this->rowTeam->getKey());
        $this->assertSame(6, $before['approved_row_findings']);
        $this->assertSame(0, $row['new']);
        $this->assertSame(10, $row['shortfall']);
        $this->assertCount(6, $before['held']);
        $this->assertTrue(collect($before['held'])->every(fn ($h) => $h['reason'] === 'BUTUH_JENIS_DAN_TIM_ROW'));

        $plan = $this->plan($findings, $this->rowTeam->getKey());
        $after = $pilot->preview(self::DATE, $plan);
        $row = $this->row($after, $this->rowTeam->getKey());
        $this->assertSame([0, 6, 4], [$row['system_existing'], $row['new'], $row['shortfall']]);
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, WorkOrderLocationTarget::query()->count());
        $this->assertSame(0, DB::table('apkuk_generation_runs')->count());
        $this->assertSame(0, DB::table('inspection_findings')->whereNotNull('follow_up_work_order_id')->count());
    }

    public function test_two_row_teams_get_ten_system_wo_each_and_manual_wo_does_not_reduce_target(): void
    {
        $second = FieldTeam::create(['code' => 'ROW-2', 'name' => 'Tim ROW kedua', 'role' => 'ROW']);
        $first = [];
        $other = [];
        foreach (range(1, 12) as $i) {
            $first[] = $this->finding();
            $other[] = $this->finding();
        }
        WorkOrder::create([
            'code' => 'WO-MANUAL-ROW-V9', 'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_RABAS, 'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan, 'field_team_id' => $this->rowTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(), 'work_date' => self::DATE,
        ]);
        $plan = $this->plan($first, $this->rowTeam->getKey())
            + $this->plan($other, $second->getKey(), WorkOrder::TYPE_TEBANG);
        $pilot = app(RowDailyGeneratorPilot::class);
        $preview = $pilot->preview(self::DATE, $plan);
        $this->assertSame([1, 10, 0], [
            $this->row($preview, $this->rowTeam->getKey())['manual'],
            $this->row($preview, $this->rowTeam->getKey())['new'],
            $this->row($preview, $this->rowTeam->getKey())['shortfall'],
        ]);
        $this->assertSame(10, $this->row($preview, $second->getKey())['new']);
        $result = $pilot->generateForIsolatedTest(self::DATE, $plan);
        $this->assertSame(20, $result['count']);
        $this->assertSame(20, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->where('team_type', WorkOrder::TEAM_ROW)->count());
        $this->assertSame(1, WorkOrder::query()->where('source', WorkOrder::SOURCE_ADMIN_MANUAL)->count());
        $this->assertSame(10, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->where('field_team_id', $this->rowTeam->getKey())->count());
        $this->assertSame(10, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->where('field_team_id', $second->getKey())->count());
        $this->assertSame(20, InspectionFinding::query()->whereNotNull('follow_up_work_order_id')->count());
        $this->assertSame(20, WorkOrderLocationTarget::query()->count());
        $sample = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->firstOrFail();
        $payload = $sample->statusLogs()->firstOrFail()->payload;
        $this->assertSame('INSPEKSI', $payload['origin']);
        $this->assertSame(RowDailyGeneratorPilot::STRATEGY, $payload['strategy']);
        $this->assertNull($sample->created_by_user_id);
        $this->assertNull($sample->due_at, 'Jadwal ROW belum boleh ditebak.');
        $this->assertSame(0, DB::table('apkuk_shift_allocations')->count());

        $repeat = $pilot->generateForIsolatedTest(self::DATE, $plan);
        $this->assertSame(0, $repeat['count']);
        $this->assertSame(20, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
    }

    public function test_pending_wrong_team_duplicate_point_and_invalid_assignment_are_held(): void
    {
        $pending = $this->finding(['validation_status' => InspectionFinding::VALIDATION_MENUNGGU]);
        $notRow = $this->finding(['suggested_team_type' => WorkOrder::TEAM_HARSUS]);
        $a = $this->finding(['latitude' => -5.3333000, 'longitude' => 105.3333000]);
        $b = $this->finding(['latitude' => -5.3333000, 'longitude' => 105.3333000]);
        $invalid = $this->finding();
        $plan = $this->plan([$pending, $notRow, $a, $b], $this->rowTeam->getKey());
        $plan[$invalid->getKey()] = [
            'field_team_id' => $this->rowTeam->getKey(), 'work_type' => 'PEMASANGAN_APKUK',
        ];
        $preview = app(RowDailyGeneratorPilot::class)->preview(self::DATE, $plan);
        $this->assertSame(3, $preview['approved_row_findings']);
        $this->assertSame(0, $this->row($preview, $this->rowTeam->getKey())['new']);
        $this->assertSame(2, collect($preview['held'])->where('reason', 'TEMUAN_GANDA_SATU_TITIK')->count());
        $this->assertSame(1, collect($preview['held'])->where('reason', 'BUTUH_JENIS_DAN_TIM_ROW')->count());
    }

    public function test_active_wo_at_same_point_is_not_selected_for_a_different_row_work_type(): void
    {
        $a = $this->finding();
        $pilot = app(RowDailyGeneratorPilot::class);
        $pilot->generateForIsolatedTest(self::DATE, $this->plan([$a], $this->rowTeam->getKey(), WorkOrder::TYPE_RABAS));
        $b = $this->finding(['latitude' => (float) $a->latitude, 'longitude' => (float) $a->longitude]);
        $preview = $pilot->preview(self::DATE, $this->plan([$b], $this->rowTeam->getKey(), WorkOrder::TYPE_TEBANG));
        $this->assertSame(0, $this->row($preview, $this->rowTeam->getKey())['new']);
        $this->assertSame(1, collect($preview['held'])->where('reason', 'WO_ROW_AKTIF_DI_TITIK')->count());
        $this->assertSame(1, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
    }

    public function test_generate_is_refused_without_explicit_flag_or_non_memory_database(): void
    {
        $this->finding();
        config(['row.pilot_generation_enabled' => false]);
        try {
            app(RowDailyGeneratorPilot::class)->generateForIsolatedTest(self::DATE, []);
            $this->fail('Pilot must be disabled without a test-only flag.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PILOT ROW V9 DITOLAK', $e->getMessage());
        }
        config(['row.pilot_generation_enabled' => true]);
        config(['database.connections.sqlite.database' => 'local.sqlite']);
        try {
            app(RowDailyGeneratorPilot::class)->preview(self::DATE);
            $this->fail('Preview must refuse file-backed SQLite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SQLite :memory:', $e->getMessage());
        }
        config(['database.connections.sqlite.database' => ':memory:']);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function finding(array $overrides = []): InspectionFinding
    {
        $this->sequence++;
        $i = $this->sequence;
        $inspection = Inspection::create([
            'code' => 'INS-ROW-V9-'.$i,
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
            'description' => 'Temuan vegetasi ke-'.$i,
            'latitude' => -5.1 - ($i / 1000),
            'longitude' => 105.1 + ($i / 1000),
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ], $overrides));
    }

    /**
     * @param array<int, InspectionFinding> $findings
     * @return array<string, array{field_team_id: string, work_type: string}>
     */
    private function plan(array $findings, string $teamId, string $type = WorkOrder::TYPE_PANGKAS): array
    {
        $plan = [];
        foreach ($findings as $finding) {
            $plan[$finding->getKey()] = ['field_team_id' => $teamId, 'work_type' => $type];
        }
        return $plan;
    }

    /** @return array<string, mixed> */
    private function row(array $preview, string $teamId): array
    {
        foreach ($preview['rows'] as $row) {
            if ($row['field_team_id'] === $teamId) {
                return $row;
            }
        }
        $this->fail('Tim ROW tidak ditemukan dalam preview.');
    }
}
