<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\ApkukGenerationRun;
use App\Models\ApkukReconciliationItem;
use App\Models\ApkukShiftAllocation;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Services\Apkuk\Generation\ApkukGeneratorGuard;
use App\Services\Apkuk\Generation\ApkukShiftGenerator;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\Apkuk\Generation\BaselineCandidateRanking;
use App\Services\Apkuk\Generation\CandidateRanking;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\ApkukCandidateService;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderReviewService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B2.3–B2.6 — kuota enam WO APKUK per Posko per shift, limpahan sah, kekurangan/kelebihan,
 * generator idempoten, pratinjau, pengaman aktivasi, dan monitoring Admin.
 * Waktu uji: 29 Okt 2026 15:00 WIB (Shift A hari operasional 29); target: Shift B hari operasional 29.
 */
class ApkukShiftGeneratorTest extends OperationsTestCase
{
    private const DATE = '2026-10-29';

    private const SHIFT = 'B';

    private Posko $wayJepara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 29, 15, 0, 0, ShiftService::TIMEZONE));
        config(['apkuk.generator.enabled' => true]);
        $this->wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        foreach ([['REOG', 'SEC PUGUNG', 'PUGUNG'], [null, 'SEC WAYJEP', 'WAY JEPARA']] as $i => [$penyulang, $pemutus, $posko]) {
            DB::table('section_pic_source_rows')->insert([
                'penyulang_raw' => $penyulang, 'pemutus_raw' => $pemutus, 'posko_pic_raw' => $posko,
                'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, array{int, int, int, int, int, int}>
     */
    public static function quotaCases(): array
    {
        // [limpahan, kandidat, baru, total, kekurangan, kelebihan]
        return [
            'A limpahan 0, kandidat 8' => [0, 8, 6, 6, 0, 0],
            'B limpahan 2, kandidat 10' => [2, 10, 4, 6, 0, 0],
            'C limpahan 2, kandidat 2' => [2, 2, 2, 4, 2, 0],
            'D limpahan 6, kandidat 10' => [6, 10, 0, 6, 0, 0],
            'E limpahan 8, kandidat 10' => [8, 10, 0, 8, 0, 2],
            'F limpahan 0, kandidat 0' => [0, 0, 0, 0, 6, 0],
        ];
    }

    #[DataProvider('quotaCases')]
    public function test_quota_with_carry_over_shortfall_and_overload(int $carryOver, int $candidates, int $new, int $total, int $shortfall, int $overload): void
    {
        $carried = collect($carryOver > 0 ? range(1, $carryOver) : [])->map(fn (int $i) => $this->carryOver($this->posko, (string) $i));
        $this->candidates($candidates, 'SEC PUGUNG');

        $run = $this->generate();
        $row = $this->summaryFor($run, 'PUGUNG');

        $this->assertSame(ApkukGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([$carryOver, $new, $total, $shortfall, $overload], [$row['carry_over'], $row['new'], $row['total'], $row['shortfall'], $row['overload']]);
        $this->assertSame($new, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->where('posko_id', $this->posko->id)->count());
        $allocation = ApkukShiftAllocation::query()->where('posko_id', $this->posko->id)->sole();
        $this->assertSame([$carryOver, $new, $total, $shortfall, $overload], [$allocation->carry_over_count, $allocation->generated_count, $allocation->total_count, $allocation->shortfall_count, $allocation->overload_count]);
        // Limpahan tetap WO yang sama: ID, status, dan jadwal tidak berubah, tidak ada yang dibatalkan.
        foreach ($carried as $workOrder) {
            $fresh = $workOrder->fresh();
            $this->assertSame([WorkOrderStatus::DijadwalkanUlang, self::DATE, self::SHIFT], [$fresh->status, $fresh->work_date->toDateString(), $fresh->shift_code]);
        }
        $this->assertSame(0, WorkOrder::query()->where('status', WorkOrderStatus::Dibatalkan->value)->count());
    }

    public function test_quota_formula_matches_final_rules(): void
    {
        $this->assertSame(4, ApkukShiftQuota::newCount(2, 0, 10));
        $this->assertSame(2, ApkukShiftQuota::newCount(2, 0, 2));
        $this->assertSame(0, ApkukShiftQuota::newCount(8, 0, 10));
        $this->assertSame(['target' => 6, 'carry_over' => 8, 'existing' => 0, 'new_slots' => 0, 'new' => 0, 'total' => 8, 'shortfall' => 0, 'overload' => 2], ApkukShiftQuota::compute(8, 0, 0));
        $this->assertSame(['target' => 6, 'carry_over' => 2, 'existing' => 0, 'new_slots' => 4, 'new' => 2, 'total' => 4, 'shortfall' => 2, 'overload' => 0], ApkukShiftQuota::compute(2, 0, 2));
    }

    public function test_unfinished_work_without_approved_reschedule_is_not_carried_over(): void
    {
        // WO shift A yang belum selesai dan WO dengan reschedule yang masih menunggu ACC tetap di shift asalnya.
        $unfinished = $this->workOrder($this->posko, 'BELUM-SELESAI', 'A', WorkOrderStatus::Ditugaskan);
        $pending = $this->workOrder($this->posko, 'MENUNGGU-ACC', 'A', WorkOrderStatus::RescheduleDiajukan);
        WorkOrderReschedule::create([
            'work_order_id' => $pending->id, 'reason_code' => 'CUACA', 'note' => 'Hujan deras', 'from_work_date' => self::DATE,
            'from_shift_code' => 'A', 'proposed_work_date' => self::DATE, 'proposed_shift_code' => self::SHIFT, 'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);
        $this->candidates(8, 'SEC PUGUNG');

        $row = $this->summaryFor($this->generate(), 'PUGUNG');

        $this->assertSame([0, 0, 6], [$row['carry_over'], $row['existing'], $row['new']]);
        $this->assertSame('A', $unfinished->fresh()->shift_code);
        $this->assertSame('A', $pending->fresh()->shift_code);
    }

    public function test_repeated_reschedules_count_the_current_target_once(): void
    {
        $workOrder = $this->carryOver($this->posko, 'BERULANG');
        // Riwayat: A → C hari 28 → B hari 29 (dua keputusan disetujui); hanya jadwal berlaku yang dihitung.
        WorkOrderReschedule::create([
            'work_order_id' => $workOrder->id, 'reason_code' => 'CUACA', 'note' => 'Reschedule pertama', 'from_work_date' => '2026-10-28',
            'from_shift_code' => 'A', 'proposed_work_date' => '2026-10-28', 'proposed_shift_code' => 'C', 'status' => WorkOrderReschedule::STATUS_DISETUJUI,
        ]);
        $this->candidates(8, 'SEC PUGUNG');

        $row = $this->summaryFor($this->generate(), 'PUGUNG');

        $this->assertSame([1, 5, 6], [$row['carry_over'], $row['new'], $row['total']]);
        $this->assertSame(['WO-LIMPAH-BERULANG'], $row['carry_over_codes']);
    }

    public function test_manual_work_already_scheduled_occupies_a_slot(): void
    {
        $this->workOrder($this->posko, 'MANUAL', self::SHIFT, WorkOrderStatus::Ditugaskan);
        $this->candidates(8, 'SEC PUGUNG');

        $row = $this->summaryFor($this->generate(), 'PUGUNG');

        $this->assertSame([0, 1, 5, 6], [$row['carry_over'], $row['existing'], $row['new'], $row['total']]);
    }

    public function test_generator_is_idempotent_and_records_provenance(): void
    {
        $this->candidates(8, 'SEC PUGUNG');
        $first = $this->generate();
        $codes = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->orderBy('code')->pluck('code')->all();

        $second = $this->generate();

        $this->assertCount(6, $codes);
        $this->assertSame($codes, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->orderBy('code')->pluck('code')->all(), 'Eksekusi ulang tidak menambah WO.');
        $this->assertSame(ApkukShiftGenerator::POSKO_ALREADY_GENERATED, $this->summaryFor($second, 'PUGUNG')['status']);
        $this->assertSame(2, ApkukShiftAllocation::query()->count(), 'Satu alokasi per Posko, tidak bertambah saat eksekusi ulang.');
        $this->assertSame(1, ShiftInstance::query()->count());

        $workOrder = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->orderBy('code')->first();
        $this->assertSame([$first->id, null, self::DATE, self::SHIFT, 'SEC PUGUNG'], [$workOrder->generation_run_id, $workOrder->created_by_user_id, $workOrder->work_date->toDateString(), $workOrder->shift_code, $workOrder->section_name]);
        $this->assertSame('2026-10-29 09:00:00', $workOrder->scheduled_start_at->utc()->format('Y-m-d H:i:s'));
        $payload = $workOrder->statusLogs()->first()->payload;
        $this->assertSame([WorkOrder::SOURCE_SYSTEM, 'BASELINE_KEBUTUHAN_TERTUA', false, 1], [$payload['source'], $payload['strategy'], $payload['is_machine_learning'], $payload['rank']]);
        $this->assertSame('SECTION_PIC_RAW', $payload['posko_mapping']['dasar']);
        $this->assertFalse($first->is_machine_learning);
    }

    public function test_baseline_ranking_is_deterministic_by_need_then_oldest_report(): void
    {
        $small = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 1, 'tanggal' => '2026-09-01', 'no_tiang' => 'F.REOG.600001']);
        $bigNew = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 5, 'tanggal' => '2026-10-20', 'no_tiang' => 'F.REOG.600002']);
        $bigOld = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 5, 'tanggal' => '2026-08-01', 'no_tiang' => 'F.REOG.600003']);

        $planned = collect($this->summaryFor($this->preview(), 'PUGUNG')['planned']);

        $this->assertSame([$bigOld, $bigNew, $small], $planned->pluck('apkuk_id')->all());
        $this->assertSame([1, 2, 3], $planned->pluck('rank')->all());
        $this->assertNull($planned->first()['score'], 'Baseline aturan tidak menghasilkan skor model.');
    }

    public function test_preview_writes_no_work_orders_and_matches_generate_plan(): void
    {
        $this->candidates(8, 'SEC PUGUNG');

        $preview = $this->preview();

        $this->assertSame(ApkukGenerationRun::MODE_PREVIEW, $preview->mode);
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, ApkukShiftAllocation::query()->count());
        $planned = collect($this->summaryFor($preview, 'PUGUNG')['planned'])->pluck('apkuk_id')->all();

        $generated = $this->generate();
        $this->assertSame($planned, collect($this->summaryFor($generated, 'PUGUNG')['created'])->pluck('apkuk_id')->all());
    }

    public function test_pending_archived_dummy_and_cross_feeder_candidates_are_never_assigned(): void
    {
        $valid = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 1, 'no_tiang' => 'F.REOG.700001']);
        $pending = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 9, 'no_tiang' => 'F.REOG.700002']);
        ApkukReconciliationItem::create([
            'apkuk_id' => $pending, 'kind' => ApkukReconciliationItem::KIND_MISSING, 'status' => ApkukReconciliationItem::STATUS_OPEN,
            'legacy_key' => 'gen-pending', 'first_detected_at' => now(), 'last_detected_at' => now(), 'detection_count' => 1,
        ]);
        $archived = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 9, 'no_tiang' => 'F.REOG.700003', 'source_missing_at' => now()]);
        $dummy = $this->apkuk(['section' => 'DUMMY', 'kurang_bh' => 9, 'no_tiang' => 'F.REOG.700004']);
        $unknown = $this->apkuk(['section' => 'SEC TIDAK ADA', 'kurang_bh' => 9, 'no_tiang' => 'F.REOG.700005']);

        $run = $this->generate();

        $created = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->with('apkukTarget')->get()->pluck('apkukTarget.apkuk_id')->all();
        $this->assertSame([$valid], $created);
        $held = collect($run->held)->keyBy('apkuk_id');
        $this->assertSame(SectionPoskoMap::REASON_SECTION_DUMMY, $held[$dummy]['reason']);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_TIDAK_DIKENAL, $held[$unknown]['reason']);
        $this->assertArrayNotHasKey($pending, $held->all(), 'Pending bukan kandidat sah, bukan sekadar ditahan.');
        $this->assertArrayNotHasKey($archived, $held->all());
        $this->assertSame('DUMMY', DB::table('apkuks')->where('id', $dummy)->value('section'));
    }

    public function test_each_candidate_goes_to_its_mapped_posko_only_and_active_targets_are_skipped(): void
    {
        $this->candidates(3, 'SEC PUGUNG');
        $this->candidates(2, 'SEC WAYJEP', 'F.REOG.8');
        $taken = $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 50, 'no_tiang' => 'F.REOG.900001']);
        // WO manual Admin pada target yang sama (menempati satu slot PUGUNG di shift ini).
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), [
            'apkuk_id' => $taken, 'posko_id' => $this->posko->id, 'work_date' => self::DATE, 'shift_code' => self::SHIFT,
        ])->assertSessionHasNoErrors();

        $this->generate();

        $system = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->with('apkukTarget')->get();
        $this->assertSame(3, $system->where('posko_id', $this->posko->id)->count());
        $this->assertSame(2, $system->where('posko_id', $this->wayJepara->id)->count());
        $this->assertSame($system->count(), $system->pluck('apkukTarget.apkuk_id')->unique()->count(), 'Tidak ada kandidat yang diberikan dua kali.');
        $this->assertNotContains($taken, $system->pluck('apkukTarget.apkuk_id')->all(), 'Target dengan WO aktif tidak dipilih lagi.');
    }

    public function test_activation_guard_blocks_generation_but_not_preview(): void
    {
        $this->candidates(8, 'SEC PUGUNG');

        config(['apkuk.generator.enabled' => false]);
        $refused = $this->generate();
        $this->assertSame(ApkukGenerationRun::STATUS_REFUSED, $refused->status);
        $this->assertStringContainsString('APKUK_GENERATOR_ENABLED', $refused->message);
        $this->assertSame(ApkukGenerationRun::STATUS_COMPLETED, $this->preview()->status);

        // Shift yang sudah berakhir tidak digenerate.
        config(['apkuk.generator.enabled' => true]);
        $ended = app(ApkukShiftGenerator::class)->run('2026-10-28', 'C', ApkukGenerationRun::MODE_GENERATE, ApkukGenerationRun::TRIGGER_CLI);
        $this->assertSame(ApkukGenerationRun::STATUS_REFUSED, $ended->status);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function operationalEnvironments(): array
    {
        // `neon` dan `workflows` adalah APP_ENV nyata di .env.neon / .env.workflows (Neon via Railway).
        return ['production' => ['production'], 'neon' => ['neon'], 'workflows' => ['workflows'], 'staging' => ['staging'], 'kosong' => ['']];
    }

    #[DataProvider('operationalEnvironments')]
    public function test_generate_is_refused_outside_development_even_with_activation_and_ml_label(string $environment): void
    {
        $this->candidates(8, 'SEC PUGUNG');
        // Strategi yang mengaku ML tetap ditolak: label isMachineLearning() bukan bukti model tervalidasi.
        $this->app->instance(CandidateRanking::class, new class implements CandidateRanking
        {
            public function name(): string
            {
                return 'MODEL_KLAIM_ML';
            }

            public function version(): string
            {
                return 'uji';
            }

            public function isMachineLearning(): bool
            {
                return true;
            }

            public function rank(Collection $candidates, ShiftInstance $shift): array
            {
                return app(BaselineCandidateRanking::class)->rank($candidates, $shift);
            }
        });
        $this->app['env'] = $environment;

        try {
            $run = $this->generate();
            $preview = $this->preview();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(ApkukGenerationRun::STATUS_REFUSED, $run->status);
        $this->assertStringContainsString('model ML terlatih dan tervalidasi serta prosedur readiness', $run->message);
        $this->assertTrue($run->is_machine_learning, 'Label strategi tetap tercatat apa adanya.');
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, ApkukShiftAllocation::query()->count());
        $this->assertSame(ApkukGenerationRun::STATUS_COMPLETED, $preview->status, 'Pratinjau tetap tersedia.');
    }

    public function test_generate_is_allowed_only_in_explicit_development_environments_with_local_database(): void
    {
        $guard = app(ApkukGeneratorGuard::class);
        $this->assertSame(['local', 'testing'], ApkukGeneratorGuard::DEVELOPMENT_ENVIRONMENTS);
        $this->assertNull($guard->blockedReason(), 'testing + aktivasi uji + SQLite');

        $this->app['env'] = 'local';
        try {
            $this->assertNull($guard->blockedReason(), 'local + aktivasi uji + SQLite');

            // local dengan database jarak jauh (mis. .env yang diarahkan ke Neon) tetap ditolak.
            config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => null]);
            $this->assertNull($guard->blockedReason());
            config(['database.connections.pgsql.host' => 'ep-contoh.ap-southeast-1.aws.neon.tech']);
            $this->assertStringContainsString('database non-lokal', (string) $guard->blockedReason());
            config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.url' => 'postgresql://u:p@ep-contoh.neon.tech/db']);
            $this->assertStringContainsString('database non-lokal', (string) $guard->blockedReason(), 'DB_URL menimpa DB_HOST.');
        } finally {
            $this->app['env'] = 'testing';
            config(['database.default' => 'sqlite']);
        }

        config(['apkuk.generator.enabled' => false]);
        $this->assertStringContainsString('APKUK_GENERATOR_ENABLED=false', (string) $guard->blockedReason());
    }

    /**
     * Alokasi beku: PUGUNG kuota 6, kandidat 2 → 2 WO + kekurangan 4. Kandidat baru muncul kemudian;
     * pratinjau tidak menjanjikan WO tambahan dan generate ulang tidak membuatnya.
     */
    public function test_frozen_allocation_keeps_preview_consistent_after_shortfall_and_new_candidates(): void
    {
        $this->candidates(2, 'SEC PUGUNG');
        $first = $this->summaryFor($this->generate(), 'PUGUNG');
        $this->assertSame([2, 6 - 2, ApkukShiftGenerator::POSKO_GENERATED], [$first['new'], $first['shortfall'], $first['status']]);

        $this->candidates(3, 'SEC PUGUNG', 'F.REOG.6');

        $preview = $this->summaryFor($this->preview(), 'PUGUNG');
        $this->assertSame(ApkukShiftGenerator::POSKO_ALREADY_GENERATED, $preview['status']);
        $this->assertSame([], $preview['planned'], 'Pratinjau tidak menjanjikan WO yang akan ditolak generate.');
        $this->assertSame([0, 2, 4, 3], [$preview['new_this_run'], $preview['total'], $preview['shortfall'], $preview['eligible_candidates']]);

        $again = $this->summaryFor($this->generate(), 'PUGUNG');
        $this->assertSame([ApkukShiftGenerator::POSKO_ALREADY_GENERATED, 0, []], [$again['status'], $again['new_this_run'], $again['created']]);
        $this->assertSame(2, WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->count(), 'Tidak ada WO tambahan otomatis; kekurangan tetap tercatat.');
        $this->assertSame(4, ApkukShiftAllocation::query()->where('posko_id', $this->posko->id)->value('shortfall_count'));
    }

    public function test_breakdown_separates_mapped_held_pending_archived_active_and_other_reasons(): void
    {
        $this->candidates(2, 'SEC PUGUNG');
        $this->candidates(1, 'SEC WAYJEP', 'F.REOG.8');
        $this->apkuk(['section' => 'DUMMY', 'no_tiang' => 'F.REOG.710001']);
        $this->apkuk(['section' => 'SEC TIDAK ADA', 'no_tiang' => 'F.REOG.710002']);
        $pending = $this->apkuk(['section' => 'SEC PUGUNG', 'no_tiang' => 'F.REOG.710003']);
        ApkukReconciliationItem::create([
            'apkuk_id' => $pending, 'kind' => ApkukReconciliationItem::KIND_MISSING, 'status' => ApkukReconciliationItem::STATUS_OPEN,
            'legacy_key' => 'breakdown-pending', 'first_detected_at' => now(), 'last_detected_at' => now(), 'detection_count' => 1,
        ]);
        $this->apkuk(['section' => 'SEC PUGUNG', 'no_tiang' => 'F.REOG.710004', 'source_missing_at' => now()]);
        $taken = $this->apkuk(['section' => 'SEC PUGUNG', 'no_tiang' => 'F.REOG.710005']);
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), [
            'apkuk_id' => $taken, 'posko_id' => $this->posko->id, 'work_date' => self::DATE, 'shift_code' => self::SHIFT,
        ])->assertSessionHasNoErrors();
        $this->apkuk(['section' => 'SEC PUGUNG', 'no_tiang' => 'F.REOG.710006', 'tanggal' => '2026-09-01']);
        $this->apkuk(['section' => 'SEC PUGUNG', 'no_tiang' => 'F.REOG.710006', 'tanggal' => '2026-10-01', 'kurang_bh' => 0, 'status' => 'SELESAI']);
        $this->apkuk(['penyulang' => 'TIDAK ADA', 'section' => 'SEC PUGUNG', 'no_tiang' => 'F.REOG.710007']);

        $breakdown = $this->preview()->breakdown;

        $this->assertSame([10, 1], [$breakdown['reports_active'], $breakdown['reports_archived']]);
        $this->assertSame([5, 3, 2], [$breakdown['eligible'], $breakdown['eligible_mapped'], $breakdown['eligible_held']]);
        $this->assertSame(['PUGUNG' => 2, 'WAY JEPARA' => 1], $breakdown['eligible_mapped_by_posko']);
        $this->assertEquals([SectionPoskoMap::REASON_SECTION_DUMMY => 1, SectionPoskoMap::REASON_SECTION_TIDAK_DIKENAL => 1], $breakdown['eligible_held_by_reason']);
        $this->assertSame(5, $breakdown['not_eligible']);
        $this->assertEquals([
            ApkukCandidateService::ISSUE_MENUNGGU_REKONSILIASI => 1,
            ApkukCandidateService::ISSUE_WO_AKTIF => 1,
            ApkukCandidateService::ISSUE_ADA_LAPORAN_LEBIH_BARU => 1,
            ApkukCandidateService::ISSUE_TIDAK_ADA_KEBUTUHAN => 1,
            ApkukCandidateService::ISSUE_PENYULANG_TIDAK_DIKENAL => 1,
        ], $breakdown['not_eligible_by_primary']);
        $this->assertSame($breakdown['not_eligible'], array_sum($breakdown['not_eligible_by_primary']));

        $page = $this->actingAs($this->admin)->get(route('admin.shift-quota.index', ['operational_date' => self::DATE, 'shift_code' => self::SHIFT]))->assertOk();
        $page->assertSee('Rincian laporan APKUK')
            ->assertSee(ApkukCandidateService::LABELS[ApkukCandidateService::ISSUE_MENUNGGU_REKONSILIASI])
            ->assertSee(ApkukCandidateService::LABELS[ApkukCandidateService::ISSUE_WO_AKTIF])
            ->assertSee(SectionPoskoMap::LABELS[SectionPoskoMap::REASON_SECTION_TIDAK_DIKENAL]);
    }

    public function test_manual_work_order_created_before_generator_occupies_the_quota(): void
    {
        $this->candidates(8, 'SEC PUGUNG');
        $this->manual($this->manualCandidate('F.REOG.700001'))->assertSessionHasNoErrors();
        $this->manual($this->manualCandidate('F.REOG.700002'))->assertSessionHasNoErrors();

        $row = $this->summaryFor($this->generate(), 'PUGUNG');

        $this->assertSame([0, 2, 4, 6, 0], [$row['carry_over'], $row['existing'], $row['new'], $row['total'], $row['overload']]);
        $this->assertSame(6, WorkOrder::query()->apkuk()->where('posko_id', $this->posko->id)->whereDate('work_date', self::DATE)->where('shift_code', self::SHIFT)->count());
    }

    public function test_manual_work_order_is_refused_once_the_shift_quota_is_full(): void
    {
        $this->candidates(8, 'SEC PUGUNG');
        $this->generate();
        $extra = $this->manualCandidate('F.REOG.700003');

        $this->manual($extra)->assertSessionHasErrors(['shift_code' => 'Kuota WO APKUK Posko PUGUNG Shift B hari operasional 29/10/2026 sudah penuh (kuota 6, terisi 6: limpahan sah 0, WO lain 6). WO tidak ditempatkan di shift ini; pilih shift lain atau batalkan WO yang tidak diperlukan.']);
        $this->assertSame(6, WorkOrder::query()->count(), 'Tidak ada WO ketujuh, tidak ada WO yang dibatalkan.');

        // Posko lain dan shift lain punya kuota sendiri.
        $this->manual($extra, self::DATE, 'C')->assertSessionHasNoErrors();
        $this->manual($this->manualCandidate('F.REOG.700004'), self::DATE, self::SHIFT, $this->wayJepara)->assertSessionHasNoErrors();
        $this->assertSame(8, WorkOrder::query()->count());
    }

    public function test_manual_can_fill_shortfall_up_to_quota_and_monitoring_separates_frozen_allocation_from_current_total(): void
    {
        $this->candidates(2, 'SEC PUGUNG');
        $this->generate();
        foreach (range(1, 4) as $i) {
            $this->manual($this->manualCandidate('F.REOG.70010'.$i))->assertSessionHasNoErrors();
        }
        $this->manual($this->manualCandidate('F.REOG.700105'))->assertSessionHasErrors('shift_code');

        $allocation = ApkukShiftAllocation::query()->where('posko_id', $this->posko->id)->sole();
        $this->assertSame([2, 2, 4], [$allocation->generated_count, $allocation->total_count, $allocation->shortfall_count], 'Angka alokasi tetap beku.');

        $page = $this->actingAs($this->admin)->get(route('admin.shift-quota.index', ['operational_date' => self::DATE, 'shift_code' => self::SHIFT]))->assertOk();
        $row = $page->viewData('rows')->firstWhere('posko.nama_posko', 'PUGUNG');
        $this->assertSame([6, 4, 2, 0, 4], [$row['total'], $row['manual']->count(), $row['generated']->count(), $row['overload'], $row['allocation_drift']]);
        $page->assertSee('Saat generate: limpahan 0 + lain 0 + dibuat 2 = 2')->assertSee('Berubah setelah generate: WO nyata kini 6 (+4)');

        $preview = $this->summaryFor($this->preview(), 'PUGUNG');
        $this->assertSame([ApkukShiftGenerator::POSKO_ALREADY_GENERATED, 2, 4, 6], [$preview['status'], $preview['total'], $preview['shortfall'], $preview['current_total']]);
    }

    public function test_eight_carry_overs_are_kept_manual_is_refused_and_approved_reschedule_may_still_overload(): void
    {
        $carried = collect(range(1, 8))->map(fn (int $i) => $this->carryOver($this->posko, (string) $i));
        $this->candidates(3, 'SEC PUGUNG');

        $row = $this->summaryFor($this->generate(), 'PUGUNG');
        $this->assertSame([8, 0, 8, 2], [$row['carry_over'], $row['new'], $row['total'], $row['overload']]);
        $this->manual($this->manualCandidate('F.REOG.700201'))->assertSessionHasErrors('shift_code');

        // Limpahan sah tetap boleh masuk walau shift sudah di atas kuota.
        $moving = $this->workOrder($this->posko, 'MASUK-LIMPAH', 'A', WorkOrderStatus::RescheduleDiajukan);
        $reschedule = WorkOrderReschedule::create([
            'work_order_id' => $moving->id, 'reason_code' => 'CUACA', 'note' => 'Hujan deras', 'from_work_date' => self::DATE,
            'from_shift_code' => 'A', 'proposed_work_date' => self::DATE, 'proposed_shift_code' => self::SHIFT, 'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);
        app(WorkOrderReviewService::class)->approveReschedule($reschedule, $this->admin, 'Disetujui');

        $this->assertSame([WorkOrderStatus::DijadwalkanUlang, self::SHIFT], [$moving->fresh()->status, $moving->fresh()->shift_code]);
        foreach ($carried as $workOrder) {
            $this->assertSame([WorkOrderStatus::DijadwalkanUlang, self::SHIFT], [$workOrder->fresh()->status, $workOrder->fresh()->shift_code]);
        }
        $this->assertSame(0, WorkOrder::query()->where('status', WorkOrderStatus::Dibatalkan->value)->count());

        $page = $this->actingAs($this->admin)->get(route('admin.shift-quota.index', ['operational_date' => self::DATE, 'shift_code' => self::SHIFT]))->assertOk();
        $pugung = $page->viewData('rows')->firstWhere('posko.nama_posko', 'PUGUNG');
        $this->assertSame([9, 9, 3, true, 1], [$pugung['total'], $pugung['carry_over']->count(), $pugung['overload'], $pugung['overload_from_carry_over'], $pugung['allocation_drift']]);
        $page->assertSee('dari limpahan sah, dipertahankan');
    }

    public function test_admin_schedule_edit_cannot_move_apkuk_work_order_into_a_full_shift(): void
    {
        $this->candidates(8, 'SEC PUGUNG');
        $this->generate();
        $this->manual($this->manualCandidate('F.REOG.700301'), self::DATE, 'C')->assertSessionHasNoErrors();
        $workOrder = WorkOrder::query()->where('source', WorkOrder::SOURCE_ADMIN_MANUAL)->sole();

        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $workOrder), ['work_date' => self::DATE, 'shift_code' => self::SHIFT, 'reason' => 'Pindah ke shift sore'])
            ->assertSessionHasErrors('shift_code');
        $this->assertSame('C', $workOrder->fresh()->shift_code);

        // Koreksi tanpa pindah jadwal dan pindah ke shift yang masih punya slot tetap boleh.
        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $workOrder), ['work_date' => self::DATE, 'shift_code' => 'C', 'notes' => 'Bawa tangga', 'reason' => 'Tambah catatan'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $workOrder), ['work_date' => '2026-10-30', 'shift_code' => 'A', 'reason' => 'Pindah ke besok pagi'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-30', 'A'], [$workOrder->fresh()->work_date->toDateString(), $workOrder->fresh()->shift_code]);
    }

    public function test_shift_c_quota_uses_the_previous_operational_date(): void
    {
        // 30 Okt 02:00 WIB masih Shift C hari operasional 29.
        $this->travelTo(Carbon::create(2026, 10, 30, 2, 0, 0, ShiftService::TIMEZONE));
        $this->candidates(8, 'SEC PUGUNG');

        $run = app(ApkukShiftGenerator::class)->run(self::DATE, 'C', ApkukGenerationRun::MODE_GENERATE, ApkukGenerationRun::TRIGGER_CLI);

        $this->assertSame(ApkukGenerationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([self::DATE, 'C'], [$run->shiftInstance->operational_date->toDateString(), $run->shiftInstance->shift_code]);
        $system = WorkOrder::query()->where('source', WorkOrder::SOURCE_SYSTEM)->get();
        $this->assertSame(6, $system->count());
        $this->assertTrue($system->every(fn (WorkOrder $wo) => $wo->work_date->toDateString() === self::DATE && $wo->shift_code === 'C'
            && $wo->scheduled_start_at->utc()->format('Y-m-d H:i') === '2026-10-29 17:00'), 'Shift C hari 29 = 30 Okt 00:00–08:00 WIB.');

        $this->manual($this->manualCandidate('F.REOG.700401'), self::DATE, 'C')->assertSessionHasErrors('shift_code');
        $this->manual($this->manualCandidate('F.REOG.700402'), '2026-10-30', 'C')->assertSessionHasNoErrors();
        $this->assertSame(1, WorkOrder::query()->whereDate('work_date', '2026-10-30')->where('shift_code', 'C')->count());
    }

    public function test_concurrent_claim_of_the_same_allocation_creates_no_duplicates(): void
    {
        $this->candidates(8, 'SEC PUGUNG');
        $injected = false;
        // Proses lain mengklaim alokasi PUGUNG tepat setelah generator membaca daftar alokasi.
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '"apkuk_shift_allocations"')) {
                $injected = true;
                $other = ApkukGenerationRun::query()->latest('id')->first();
                DB::table('apkuk_shift_allocations')->insert([
                    'shift_instance_id' => $other->shift_instance_id, 'posko_id' => $this->posko->id, 'generation_run_id' => $other->id,
                    'target_count' => 6, 'carry_over_count' => 0, 'existing_count' => 0, 'generated_count' => 0, 'total_count' => 0,
                    'shortfall_count' => 0, 'overload_count' => 0, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $run = $this->generate();

        $this->assertTrue($injected);
        $this->assertSame(ApkukGenerationRun::STATUS_REFUSED, $run->status);
        $this->assertSame(0, WorkOrder::query()->count(), 'Transaksi generator dibatalkan utuh.');
    }

    public function test_admin_monitoring_shows_real_preview_and_held_data_and_requires_admin(): void
    {
        $this->candidates(2, 'SEC PUGUNG');
        $held = $this->apkuk(['section' => 'DUMMY', 'kurang_bh' => 3, 'no_tiang' => 'F.REOG.990001']);
        $query = ['operational_date' => self::DATE, 'shift_code' => self::SHIFT];

        $this->get(route('admin.shift-quota.index', $query))->assertRedirect(route('login'));
        $this->actingAs($this->fieldAccount($this->poskoTeam, 'posko_kuota'))->get(route('admin.shift-quota.index', $query))->assertForbidden();
        $this->actingAs($this->fieldAccount($this->poskoTeam, 'posko_kuota2'))->post(route('admin.shift-quota.run'), $query + ['mode' => 'GENERATE'])->assertForbidden();
        auth()->forgetGuards();

        $this->actingAs($this->admin)->post(route('admin.shift-quota.run'), $query + ['mode' => ApkukGenerationRun::MODE_PREVIEW])
            ->assertRedirect(route('admin.shift-quota.index', $query))->assertSessionHas('flash', 'Pratinjau selesai. Tidak ada WO yang dibuat.');
        $this->assertSame(0, WorkOrder::query()->count());

        $page = $this->actingAs($this->admin)->get(route('admin.shift-quota.index', $query))->assertOk();
        $page->assertSee('SIMULASI')->assertSee('Rule-based (bukan ML)')->assertSee('baseline rule-based, bukan machine learning', false)
            ->assertSee('F.REOG.990001')->assertSee(SectionPoskoMap::LABELS[SectionPoskoMap::REASON_SECTION_DUMMY])
            ->assertSee('Kekurangan')->assertSee('Lingkungan: testing');
        $this->assertSame(0, $page->viewData('rows')->firstWhere('posko.nama_posko', 'PUGUNG')['generated']->count(), 'WO nyata belum ada setelah pratinjau.');

        config(['apkuk.generator.enabled' => false]);
        $this->actingAs($this->admin)->get(route('admin.shift-quota.index', $query))->assertOk()->assertSee('Generator otomatis nonaktif');
        $this->actingAs($this->admin)->post(route('admin.shift-quota.run'), $query + ['mode' => ApkukGenerationRun::MODE_GENERATE])->assertSessionHasErrors('generator');
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_command_previews_by_default_and_scheduler_is_not_registered_while_disabled(): void
    {
        $this->candidates(1, 'SEC PUGUNG');

        $this->assertSame(0, Artisan::call('apkuk:generate-shift', ['--date' => self::DATE, '--shift' => self::SHIFT]));
        $this->assertSame(0, WorkOrder::query()->count());
        config(['apkuk.generator.enabled' => false]);
        $this->assertSame(1, Artisan::call('apkuk:generate-shift', ['--date' => self::DATE, '--shift' => self::SHIFT, '--generate' => true]));
        $this->assertSame(0, WorkOrder::query()->count());

        // routes/console.php dimuat saat boot dengan konfigurasi default (nonaktif).
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
        $this->assertStringNotContainsString('apkuk:generate-shift', $commands);
    }

    private function generate(): ApkukGenerationRun
    {
        return app(ApkukShiftGenerator::class)->run(self::DATE, self::SHIFT, ApkukGenerationRun::MODE_GENERATE, ApkukGenerationRun::TRIGGER_CLI);
    }

    private function preview(): ApkukGenerationRun
    {
        return app(ApkukShiftGenerator::class)->run(self::DATE, self::SHIFT, ApkukGenerationRun::MODE_PREVIEW, ApkukGenerationRun::TRIGGER_CLI);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryFor(ApkukGenerationRun $run, string $posko): array
    {
        return collect($run->summary)->firstWhere('posko', $posko);
    }

    private function manual(int $apkukId, string $date = self::DATE, string $shift = self::SHIFT, ?Posko $posko = null): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.work-orders.store'), [
            'apkuk_id' => $apkukId, 'posko_id' => ($posko ?? $this->posko)->id, 'work_date' => $date, 'shift_code' => $shift,
        ]);
    }

    private function manualCandidate(string $pole): int
    {
        return $this->apkuk(['section' => 'SEC PUGUNG', 'kurang_bh' => 1, 'no_tiang' => $pole]);
    }

    private function candidates(int $count, string $section, string $prefix = 'F.REOG.5'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->apkuk(['section' => $section, 'kurang_bh' => 20 - $i, 'no_tiang' => $prefix.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
        }
    }

    private function workOrder(Posko $posko, string $code, string $shift, WorkOrderStatus $status): WorkOrder
    {
        return WorkOrder::create([
            'code' => 'WO-'.$code, 'team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL, 'status' => $status, 'posko_id' => $posko->id,
            'penyulang_id' => $this->penyulang->id, 'work_date' => self::DATE, 'shift_code' => $shift,
        ]);
    }

    /**
     * WO yang dijadwalkan ulang secara sah ke Shift B hari operasional 29 (reschedule disetujui).
     */
    private function carryOver(Posko $posko, string $suffix): WorkOrder
    {
        $workOrder = $this->workOrder($posko, 'LIMPAH-'.$suffix, self::SHIFT, WorkOrderStatus::DijadwalkanUlang);
        WorkOrderReschedule::create([
            'work_order_id' => $workOrder->id, 'reason_code' => 'CUACA', 'note' => 'Hujan deras', 'from_work_date' => '2026-10-29',
            'from_shift_code' => 'A', 'proposed_work_date' => self::DATE, 'proposed_shift_code' => self::SHIFT, 'status' => WorkOrderReschedule::STATUS_DISETUJUI,
        ]);

        return $workOrder;
    }
}
