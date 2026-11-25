<?php

namespace Tests\Feature\Operations;

use App\Models\ApkukGenerationRun;
use App\Models\ApkukShiftAllocation;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\WorkOrder;
use App\Services\Inspection\InspectionApkukWorkOrderService;
use App\Services\Inspection\InspectionFullGeneratorPilot;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V6A isolated pilot: no web/CLI/scheduler integration and no PostgreSQL writes.
 */
class InspectionFullGeneratorPilotTest extends OperationsTestCase
{
    private const DATE = '2026-11-23';
    private const SHIFT = 'A';
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 10, 0, 0, ShiftService::TIMEZONE));
        config(['apkuk.generator.enabled' => true]);
        $this->addMapping('SEC PUGUNG', 'PUGUNG');
    }

    public function test_read_only_preview_uses_approved_inspections_regardless_of_legacy_type_and_never_sps(): void
    {
        $this->apkuk(['no_tiang' => 'SPS.REOG.1', 'section' => 'SEC PUGUNG']);
        $this->finding('F.REOG.1', 2);
        $this->finding('F.REOG.PENDING', 4, InspectionFinding::VALIDATION_MENUNGGU);
        $this->finding('F.REOG.REGULAR', 4, InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_REGULAR);
        $this->finding('F.REOG.ROW', 4, InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_FULL, 'SEC PUGUNG', WorkOrder::TEAM_ROW);

        $preview = app(InspectionFullGeneratorPilot::class)->preview(self::DATE, self::SHIFT);
        $pugung = $this->poskoRow($preview, 'PUGUNG');

        $this->assertSame([2, 2, 2, 4], [$preview['source_total'], $preview['eligible'], $pugung['new'], $pugung['shortfall']]);
        $this->assertSame('F.REOG.REGULAR', $pugung['planned'][0]['no_tiang']);
        $this->assertEqualsCanonicalizing(['F.REOG.REGULAR', 'F.REOG.1'], array_column($pugung['planned'], 'no_tiang'));
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, ApkukGenerationRun::query()->count());
        $this->assertSame(0, DB::table('shift_instances')->count());
        $this->assertSame(1, DB::table('apkuks')->count(), 'SPS test source was not consumed or mutated.');
    }

    public function test_six_system_wo_are_created_with_real_inspection_provenance(): void
    {
        foreach (range(1, 8) as $i) {
            $this->finding('F.REOG.'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), $i);
        }
        $pilot = app(InspectionFullGeneratorPilot::class);
        $run = $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
        $this->assertSame(ApkukGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(6, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(0, DB::table('apkuks')->count());
        $this->assertSame(6, DB::table('inspection_findings')->whereNotNull('follow_up_work_order_id')->count());

        $targets = DB::table('work_order_apkuk_targets')->get();
        $this->assertCount(6, $targets);
        $this->assertTrue($targets->every(fn ($t) => $t->apkuk_id === null && $t->inspection_finding_id !== null));
        $this->assertSame(6, $targets->pluck('target_key')->unique()->count());
        $quantity = $targets->pluck('target_quantity')->sortDesc()->values()->all();
        $this->assertSame([8, 7, 6, 5, 4, 3], $quantity);
        $first = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->firstOrFail();
        $log = $first->statusLogs()->firstOrFail()->payload;
        $this->assertSame('INSPEKSI', $log['origin']);
        $this->assertSame($run->getKey(), $log['generation_run_id']);
        $this->assertSame(6, DB::table('apkuk_shift_allocations')->where('posko_id', $this->posko->getKey())->value('generated_count'));
    }

    public function test_manual_inspection_wo_does_not_reduce_six_system_slots(): void
    {
        $manual = $this->finding('F.REOG.MANUAL', 2);
        app(InspectionApkukWorkOrderService::class)->createManual($manual, $this->admin, [
            'posko_id' => $this->posko->getKey(),
            'work_date' => self::DATE, 'shift_code' => self::SHIFT,
        ]);
        foreach (range(1, 6) as $i) {
            $this->finding('F.REOG.AUTO.'. $i, 2);
        }
        $pilot = app(InspectionFullGeneratorPilot::class);
        $preview = $this->poskoRow($pilot->preview(self::DATE, self::SHIFT), 'PUGUNG');
        $this->assertSame([0, 1, 6, 6], [$preview['system_existing'], $preview['manual_other'], $preview['new'], $preview['system_after']]);
        $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);

        $this->assertSame(7, WorkOrder::query()->count());
        $this->assertSame(6, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(1, WorkOrder::query()->where('source', WorkOrder::SOURCE_ADMIN_MANUAL)->count());
        $this->assertSame(6, DB::table('apkuk_shift_allocations')->where('posko_id', $this->posko->getKey())->value('generated_count'));
    }

    public function test_ambiguous_same_pole_and_unmapped_section_are_held(): void
    {
        $this->finding('F.REOG.AMBIGU', 3);
        $this->finding('F.REOG.AMBIGU', 4);
        $this->finding('F.REOG.NOSECTION', 5, InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_FULL, 'UNKNOWN');
        $this->finding('F.REOG.OK', 1);
        $preview = app(InspectionFullGeneratorPilot::class)->preview(self::DATE, self::SHIFT);
        $row = $this->poskoRow($preview, 'PUGUNG');

        $this->assertSame(1, $row['new']);
        $this->assertSame(1, $preview['eligible']);
        $this->assertSame(2, collect($preview['held'])->where('reason', 'TEMUAN_GANDA_SATU_TIANG')->count());
        $this->assertContains('SECTION_TIDAK_DIKENAL', array_column($preview['held'], 'reason'));
    }

    public function test_duplicate_pole_with_one_unmapped_section_is_not_silently_selected(): void
    {
        $this->finding('F.REOG.MIXED', 4);
        $this->finding('F.REOG.MIXED', 5, InspectionFinding::VALIDATION_DISETUJUI,
            Inspection::TYPE_FULL, 'UNKNOWN SECTION');
        $preview = app(InspectionFullGeneratorPilot::class)->preview(self::DATE, self::SHIFT);
        $row = $this->poskoRow($preview, 'PUGUNG');

        $this->assertSame(0, $row['new']);
        $this->assertSame(0, $preview['eligible']);
        $this->assertSame(2, collect($preview['held'])->where('reason', 'TEMUAN_GANDA_SATU_TIANG')->count());
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_legacy_zero_allocation_is_not_overwritten_or_planned(): void
    {
        $this->finding('F.REOG.LEGACY.ALLOC', 2);
        Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        $this->addMapping('SEC WAYJEP', 'WAY JEPARA');
        $this->finding('F.REOG.LEGACY.OTHER', 2, InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_FULL, 'SEC WAYJEP');
        $shift = ShiftInstance::ensure(app(ShiftService::class), self::DATE, self::SHIFT);
        $legacyRun = ApkukGenerationRun::create([
            'shift_instance_id' => $shift->getKey(),
            'mode' => ApkukGenerationRun::MODE_GENERATE,
            'status' => ApkukGenerationRun::STATUS_COMPLETED,
            'trigger' => ApkukGenerationRun::TRIGGER_CLI,
            'strategy' => 'SPS_LEGACY_BASELINE',
            'strategy_version' => 'v1',
            'is_machine_learning' => false,
            'app_environment' => 'testing',
            'started_at' => now(),
        ]);
        $allocation = ApkukShiftAllocation::create([
            'shift_instance_id' => $shift->getKey(),
            'posko_id' => $this->posko->getKey(),
            'generation_run_id' => $legacyRun->getKey(),
            'target_count' => 6,
            'carry_over_count' => 0,
            'existing_count' => 0,
            'generated_count' => 0,
            'total_count' => 0,
            'shortfall_count' => 6,
            'overload_count' => 0,
            'generated_at' => now(),
        ]);
        $pilot = app(InspectionFullGeneratorPilot::class);
        $preview = $pilot->preview(self::DATE, self::SHIFT);
        $row = $this->poskoRow($preview, 'PUGUNG');
        $other = $this->poskoRow($preview, 'WAY JEPARA');
        $this->assertTrue($preview['blocked_by_legacy']);
        $this->assertSame(1, $other['eligible']);
        $this->assertFalse($other['legacy_allocation_conflict']);
        $this->assertSame([], $other['planned']);
        $this->assertSame(0, $other['new']);
        $this->assertSame(6, $other['shortfall']);
        $this->assertTrue($row['legacy_allocation_conflict']);
        $this->assertSame(0, $row['new']);
        $this->assertSame([], $row['planned']);

        $this->actingAs($this->admin)->get(route('admin.shift-quota.inspection-full', [
            'operational_date' => self::DATE, 'shift_code' => self::SHIFT,
        ]))->assertOk()->assertSee('Seluruh shift ditahan.')
            ->assertSee('Tidak ada rencana baru karena seluruh shift ditahan.');

        try {
            $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
            $this->fail('Generation should refuse a legacy allocation.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('SPS legacy', $exception->getMessage());
        }
        $allocation->refresh();
        $this->assertSame($legacyRun->getKey(), $allocation->generation_run_id);
        $this->assertSame(0, $allocation->generated_count);
        $this->assertSame(1, ApkukGenerationRun::query()->count());
        $this->assertSame(0, WorkOrder::query()->count());

        // An inactive Posko still owns its old allocation; do not ignore it.
        $this->posko->update(['is_active' => false]);
        $inactivePreview = $pilot->preview(self::DATE, self::SHIFT);
        $this->assertTrue($inactivePreview['blocked_by_legacy']);
        $this->assertSame(0, $this->poskoRow($inactivePreview, 'WAY JEPARA')['new']);
        try {
            $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
            $this->fail('Generation must refuse an inactive Posko legacy allocation.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('SPS legacy', $exception->getMessage());
        }
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(1, ApkukGenerationRun::query()->count());
    }

    public function test_legacy_system_wo_from_inactive_posko_blocks_entire_original_shift(): void
    {
        $oldPosko = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA', 'is_active' => false]);
        $this->finding('F.REOG.CLEAR.POSKO', 3);
        $shift = ShiftInstance::ensure(app(ShiftService::class), self::DATE, self::SHIFT);
        $legacyRun = ApkukGenerationRun::create([
            'shift_instance_id' => $shift->getKey(),
            'mode' => ApkukGenerationRun::MODE_GENERATE,
            'status' => ApkukGenerationRun::STATUS_COMPLETED,
            'trigger' => ApkukGenerationRun::TRIGGER_CLI,
            'strategy' => 'SPS_LEGACY_BASELINE',
            'strategy_version' => 'v1',
            'is_machine_learning' => false,
            'app_environment' => 'testing',
            'started_at' => now(),
        ]);
        // Even an old WO without target provenance must prevent mixing sources.
        WorkOrder::create([
            'code' => 'WO-LEGACY-INACTIVE',
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_SYSTEM,
            'status' => \App\Enums\WorkOrderStatus::Ditugaskan,
            'posko_id' => $oldPosko->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'work_date' => self::DATE,
            'shift_code' => self::SHIFT,
            'generation_run_id' => $legacyRun->getKey(),
        ]);
        $pilot = app(InspectionFullGeneratorPilot::class);
        $preview = $pilot->preview(self::DATE, self::SHIFT);
        $row = $this->poskoRow($preview, 'PUGUNG');
        $this->assertTrue($preview['blocked_by_legacy']);
        $this->assertSame(1, $row['eligible']);
        $this->assertSame([], $row['planned']);
        $this->assertSame(0, $row['new']);
        $this->assertSame(6, $row['shortfall']);

        // The restriction applies to the original shift only, not another shift.
        $next = $pilot->preview(self::DATE, 'B');
        $this->assertFalse($next['blocked_by_legacy']);
        $this->assertSame(1, $this->poskoRow($next, 'PUGUNG')['new']);

        try {
            $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
            $this->fail('Generation must refuse legacy WO even from an inactive Posko.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('SPS legacy', $exception->getMessage());
        }
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, ApkukGenerationRun::query()->count());
        $this->assertSame(0, ApkukShiftAllocation::query()->count());
        $this->assertSame(0, DB::table('inspection_findings')->whereNotNull('follow_up_work_order_id')->count());
    }

    public function test_repeat_is_idempotent_and_shortfall_can_top_up_without_manual_penalty(): void
    {
        foreach (range(1, 2) as $i) {
            $this->finding('F.REOG.TOPUP.'. $i, 2);
        }
        $pilot = app(InspectionFullGeneratorPilot::class);
        $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
        $this->assertSame(2, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(4, $this->poskoRow($pilot->preview(self::DATE, self::SHIFT), 'PUGUNG')['shortfall']);
        foreach (range(3, 7) as $i) {
            $this->finding('F.REOG.TOPUP.'. $i, 2);
        }
        $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
        $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);

        $this->assertSame(6, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(1, DB::table('apkuk_shift_allocations')->where('posko_id', $this->posko->getKey())->count());
        $this->assertSame(0, $this->poskoRow($pilot->preview(self::DATE, self::SHIFT), 'PUGUNG')['shortfall']);
        $this->assertSame(1, DB::table('inspection_findings')->whereNull('follow_up_work_order_id')->count());
    }

    public function test_distinct_poskos_get_independent_six_slots(): void
    {
        $other = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        $this->addMapping('SEC WAYJEP', 'WAY JEPARA');
        foreach (range(1, 6) as $i) {
            $this->finding('F.REOG.PUG.'. $i, 1);
            $this->finding('F.REOG.WJ.'. $i, 1, InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_FULL, 'SEC WAYJEP');
        }
        app(InspectionFullGeneratorPilot::class)->generateForIsolatedTest(self::DATE, self::SHIFT);
        $this->assertSame(6, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->where('posko_id', $this->posko->getKey())->count());
        $this->assertSame(6, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->where('posko_id', $other->getKey())->count());
    }

    public function test_system_wo_carried_over_from_an_earlier_shift_does_not_consume_six_new_slots(): void
    {
        $previous = ShiftInstance::ensure(app(ShiftService::class), '2026-11-22', 'B');
        $previousRun = ApkukGenerationRun::create([
            'shift_instance_id' => $previous->getKey(),
            'mode' => ApkukGenerationRun::MODE_GENERATE,
            'status' => ApkukGenerationRun::STATUS_COMPLETED,
            'trigger' => ApkukGenerationRun::TRIGGER_CLI,
            'strategy' => 'INSPEKSI_FULL_BASELINE',
            'strategy_version' => 'v6a.1',
            'is_machine_learning' => false,
            'app_environment' => 'testing',
            'started_at' => now(),
        ]);
        // This is a rescheduled SYSTEM WO originating from a previous shift.
        WorkOrder::create([
            'code' => 'WO-PREVIOUS-SYSTEM',
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_SYSTEM,
            'status' => \App\Enums\WorkOrderStatus::DijadwalkanUlang,
            'posko_id' => $this->posko->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'work_date' => self::DATE,
            'shift_code' => self::SHIFT,
            'generation_run_id' => $previousRun->getKey(),
        ]);
        foreach (range(1, 6) as $i) {
            $this->finding('F.REOG.CARRY.'. $i, 2);
        }
        $pilot = app(InspectionFullGeneratorPilot::class);
        $preview = $this->poskoRow($pilot->preview(self::DATE, self::SHIFT), 'PUGUNG');
        $this->assertSame([0, 1, 6], [$preview['system_existing'], $preview['manual_other'], $preview['new']]);
        $pilot->generateForIsolatedTest(self::DATE, self::SHIFT);
        $this->assertSame(7, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count());
        $this->assertSame(6, $this->poskoRow($pilot->preview(self::DATE, self::SHIFT), 'PUGUNG')['system_existing']);
    }

    public function test_generate_refuses_when_test_flag_off_or_without_memory_database(): void
    {
        config(['apkuk.generator.enabled' => false]);
        $this->expectException(RuntimeException::class);
        app(InspectionFullGeneratorPilot::class)->generateForIsolatedTest(self::DATE, self::SHIFT);
    }

    public function test_pg_pilot_refuses_sqlite_even_with_test_approval(): void
    {
        $previous = getenv('POSKO_PG_TEST_APPROVED');
        try {
            putenv('POSKO_PG_TEST_APPROVED=ISOLATED_POSKO_PG_TEST_ONLY');
            config(['apkuk.generator.enabled' => false, 'apkuk.inspection_pg_test_enabled' => true]);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('POSKO V6B3 DITOLAK');
            app(InspectionFullGeneratorPilot::class)->generateForIsolatedPostgresTest(self::DATE, self::SHIFT);
        } finally {
            putenv($previous === false ? 'POSKO_PG_TEST_APPROVED' : 'POSKO_PG_TEST_APPROVED='.$previous);
        }
    }

    public function test_pg_pilot_refuses_dev_remote_url_and_missing_gates_before_connection(): void
    {
        $previous = getenv('POSKO_PG_TEST_APPROVED');
        $original = config('database.connections.pgsql');
        try {
            putenv('POSKO_PG_TEST_APPROVED=ISOLATED_POSKO_PG_TEST_ONLY');
            config(['database.default' => 'pgsql', 'apkuk.generator.enabled' => false,
                'apkuk.inspection_pg_test_enabled' => true]);
            $valid = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432',
                'database' => InspectionFullGeneratorPilot::PG_TEST_DATABASE,
                'url' => null, 'search_path' => 'public', 'prefix' => ''];
            $cases = [
                ['database' => 'ulp_sribhawono_dev'], ['database' => 'neondb'],
                ['host' => 'example.invalid'], ['url' => 'pgsql://example.invalid/test'],
                ['port' => '5433'], ['search_path' => 'other'],
                ['read' => ['host' => 'example.invalid']],
            ];
            $pilot = app(InspectionFullGeneratorPilot::class);
            foreach ($cases as $case) {
                config(['database.connections.pgsql' => array_replace($valid, $case)]);
                try {
                    $pilot->generateForIsolatedPostgresTest(self::DATE, self::SHIFT);
                    $this->fail('Konfigurasi PG tidak sah harus ditolak.');
                } catch (RuntimeException $e) {
                    $this->assertStringStartsWith('POSKO V6B3 DITOLAK', $e->getMessage());
                }
            }
            config(['database.connections.pgsql' => $valid, 'apkuk.inspection_pg_test_enabled' => false]);
            try {
                $pilot->generateForIsolatedPostgresTest(self::DATE, self::SHIFT);
                $this->fail('Flag uji harus eksplisit.');
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('POSKO V6B3 DITOLAK', $e->getMessage());
            }
            config(['apkuk.inspection_pg_test_enabled' => true]);
            putenv('POSKO_PG_TEST_APPROVED');
            try {
                $pilot->generateForIsolatedPostgresTest(self::DATE, self::SHIFT);
                $this->fail('Izin proses harus eksplisit.');
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('POSKO V6B3 DITOLAK', $e->getMessage());
            }
            putenv('POSKO_PG_TEST_APPROVED=ISOLATED_POSKO_PG_TEST_ONLY');
            config(['apkuk.generator.enabled' => true]);
            try {
                $pilot->generateForIsolatedPostgresTest(self::DATE, self::SHIFT);
                $this->fail('Generator legacy harus OFF.');
            } catch (RuntimeException $e) {
                $this->assertStringStartsWith('POSKO V6B3 DITOLAK', $e->getMessage());
            }
        } finally {
            config(['database.default' => 'sqlite', 'database.connections.pgsql' => $original,
                'apkuk.generator.enabled' => false, 'apkuk.inspection_pg_test_enabled' => false]);
            putenv($previous === false ? 'POSKO_PG_TEST_APPROVED' : 'POSKO_PG_TEST_APPROVED='.$previous);
        }
        $this->assertSame(0, WorkOrder::query()->count());
    }

    private function addMapping(string $section, string $posko): void
    {
        static $row = 1;
        DB::table('section_pic_source_rows')->insert([
            'penyulang_raw' => 'REOG', 'pemutus_raw' => $section, 'posko_pic_raw' => $posko,
            'source_row_number' => ++$row, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function finding(
        string $pole,
        int $quantity,
        string $status = InspectionFinding::VALIDATION_DISETUJUI,
        string $type = Inspection::TYPE_REGULAR,
        string $section = 'SEC PUGUNG',
        string $suggestedTeam = WorkOrder::TEAM_POSKO,
    ): InspectionFinding {
        ++$this->sequence;
        $inspection = Inspection::create([
            'code' => 'INS-V6A-'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'section_name' => $section,
            'visited_at' => now()->subDays($this->sequence),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => $type,
            'submitted_by_user_id' => $this->admin->getKey(),
        ]);
        return InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'APKUK_UJI_V6A',
            'description' => 'Temuan pilot APKUK',
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'suggested_team_type' => $suggestedTeam,
            'apkuk_no_tiang' => $pole,
            'apkuk_target_quantity' => $quantity,
            'validation_status' => $status,
            'validated_by_user_id' => $status === InspectionFinding::VALIDATION_DISETUJUI ? $this->admin->getKey() : null,
            'validated_at' => $status === InspectionFinding::VALIDATION_DISETUJUI ? now() : null,
        ]);
    }

    private function poskoRow(array $preview, string $name): array
    {
        return collect($preview['rows'])->firstWhere('posko', $name);
    }
}
