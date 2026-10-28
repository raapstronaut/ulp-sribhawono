<?php

namespace Tests\Feature\Sync;

use App\Console\Support\ConsoleAdminAuthenticator;
use App\Enums\WorkOrderStatus;
use App\Models\Apkuk;
use App\Models\ApkukReconciliationItem;
use App\Models\ApkukSyncRun;
use App\Models\AuditLog;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Apkuk\ApkukReconciliationQueue;
use App\Services\Apkuk\ApkukSourceState;
use App\Services\ApkukCandidateService;
use App\Services\ApkukWorkOrderService;
use App\Services\GoogleSheetValues;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sinkronisasi APKUK yang tahan perubahan normal di SPS BOS: laporan baru/diperbarui tetap
 * masuk, laporan yang hilang masuk antrean rekonsiliasi (tidak dihapus/ditandai), perubahan
 * identitas ditahan, dan snapshot mencurigakan ditolak tanpa penulisan apa pun.
 * Google Sheets dipalsukan; database SQLite in-memory.
 */
class ApkukResilientSyncTest extends TestCase
{
    use RefreshDatabase;

    private const INITIAL_REPORTS = 3200;

    private string $credentialsPath;

    /** @var array<string, array<int, mixed>> */
    private array $reports = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 28, 1, 0, 0, 'UTC'));
        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'apkuk-cred');
        file_put_contents($this->credentialsPath, '{}');
        config([
            'services.google_sheets.credentials_path' => $this->credentialsPath,
            'services.google_sheets.spreadsheet_id' => 'sheet-test',
        ]);

        foreach (range(1, self::INITIAL_REPORTS) as $i) {
            $this->reports["A{$i}"] = $this->report("A{$i}", sprintf('F.REOG.%06d', $i));
        }
        // ID AppSheet dipakai dua laporan berbeda tiang (pola A150 di produksi).
        $this->reports['A150-B'] = $this->report('A150', 'F.REOG.900150');

        $this->admin = User::factory()->admin()->create(['username' => 'ADMIN_REKON']);
        Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);

        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->travel(15)->minutes();
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);
        $this->reports = [];

        parent::tearDown();
    }

    public function test_new_report_is_inserted_and_run_is_recorded_as_success(): void
    {
        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');

        [$code, $output] = $this->sync();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=SUCCESS exit=0 .* new=1 updated=0 returned=0 pending=0 /m', $output);
        $this->assertSame(self::INITIAL_REPORTS + 2, DB::table('apkuks')->count());
        $run = ApkukSyncRun::query()->latest('id')->first();
        $this->assertSame(ApkukSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertTrue($run->snapshot_accepted);
        $this->assertSame(self::INITIAL_REPORTS + 2, $run->source_report_count);
        $this->assertSame(1, $run->counts['inserted']);
        $this->assertSame(2, ApkukSyncRun::query()->count());
    }

    public function test_updated_report_changes_only_that_row_and_keeps_work_order_snapshot(): void
    {
        $a7 = $this->rowId('A7');
        $this->createWorkOrderTarget('WO-APKUK-UJI-0001', WorkOrderStatus::Ditugaskan, $a7);
        $untouched = (array) DB::table('apkuks')->where('source_id', 'A9')->first();
        $target = (array) DB::table('work_order_apkuk_targets')->first();

        $this->reports['A7'][8] = 5;            // Kurang bh
        $this->reports['A8'][9] = 'SELESAI';    // Status
        [$code, $output] = $this->sync();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ new=0 updated=2 returned=0 pending=0 /', $output);
        $this->assertSame(5, (int) DB::table('apkuks')->where('id', $a7)->value('kurang_bh'));
        $this->assertSame('SELESAI', DB::table('apkuks')->where('source_id', 'A8')->value('status'));

        // Laporan lain tidak ditulis ulang (hanya cap waktu terakhir terlihat di sumber).
        $after = (array) DB::table('apkuks')->where('source_id', 'A9')->first();
        $this->assertSame(array_diff_key($untouched, ['last_synced_at' => 1]), array_diff_key($after, ['last_synced_at' => 1]));
        $this->assertNotSame($untouched['last_synced_at'], $after['last_synced_at']);

        // Target WO adalah snapshot saat WO dibuat; sinkronisasi tidak mengubahnya.
        $this->assertSame($target, (array) DB::table('work_order_apkuk_targets')->first());
    }

    public function test_deleted_reports_are_queued_not_removed_while_new_reports_still_sync(): void
    {
        $ids = [$this->rowId('A1'), $this->rowId('A2'), $this->rowId('A3')];
        unset($this->reports['A1'], $this->reports['A2'], $this->reports['A3']);
        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');

        [$code, $output] = $this->sync();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 .* new=1 updated=0 returned=0 pending=3 pending_new=3 /m', $output);
        $this->assertStringContainsString('PERINGATAN: 3 laporan menunggu rekonsiliasi (3 baru pada eksekusi ini)', $output);
        $this->assertSame(1, DB::table('apkuks')->where('source_id', 'N1')->count());

        // Tidak dihapus dan tidak ditandai histori tanpa keputusan.
        $this->assertSame(3, DB::table('apkuks')->whereIn('id', $ids)->whereNull('source_missing_at')->count());
        $items = ApkukReconciliationItem::query()->open()->orderBy('apkuk_id')->get();
        $this->assertSame($ids, $items->pluck('apkuk_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([ApkukReconciliationItem::KIND_MISSING], $items->pluck('kind')->unique()->values()->all());
        $this->assertSame(ApkukSyncRun::STATUS_SUCCESS_WITH_PENDING, ApkukSyncRun::query()->latest('id')->value('status'));

        // Kandidat WO, peta, dan scope model membaca status yang sama.
        $candidates = app(ApkukCandidateService::class)->evaluate();
        $this->assertFalse($candidates[$ids[0]]->isEligible());
        $this->assertSame([ApkukCandidateService::ISSUE_MENUNGGU_REKONSILIASI], $candidates[$ids[0]]->issues);
        $this->assertTrue($candidates[$this->rowId('A4')]->isEligible());
        $this->assertSame(3, Apkuk::query()->awaitingReconciliation()->count());
        $this->assertSame(self::INITIAL_REPORTS + 1 - 3 + 1, Apkuk::query()->sourceActive()->count());
        $this->assertSame(0, Apkuk::query()->sourceHistory()->count());

        $map = $this->actingAs($this->admin)->getJson(route('admin.maps.data'))->assertOk();
        $map->assertJsonPath('meta.apkuk_pending_reconciliation', 3)
            ->assertJsonPath('meta.apkuk_pending_mapped', 3)
            ->assertJsonPath('meta.apkuk_active_total', self::INITIAL_REPORTS + 1 - 3 + 1)
            ->assertJsonPath('meta.apkuk_mapped', self::INITIAL_REPORTS + 1 - 3 + 1);
        $pending = collect($map->json('apkuks'))->where('reconciliation_pending', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($ids, $pending);
    }

    public function test_pole_change_is_held_and_admin_confirms_it_is_the_same_report(): void
    {
        $a5 = $this->rowId('A5');
        $this->createWorkOrderTarget('WO-APKUK-UJI-0002', WorkOrderStatus::Dikerjakan, $a5);
        $this->reports['A5'][5] = 'F.REOG.000005-KOREKSI';

        [$code, $output] = $this->sync();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ new=0 .* pending=1 pending_new=1 pending_resolved=0 identity_changes=1/', $output);
        $this->assertStringContainsString('ID A5 | Tiang baru F.REOG.000005-KOREKSI', $output);
        $this->assertSame(0, DB::table('apkuks')->where('no_tiang', 'F.REOG.000005-KOREKSI')->count(), 'Identitas baru ditahan, tidak disisipkan.');
        $item = ApkukReconciliationItem::query()->open()->sole();
        $this->assertSame(ApkukReconciliationItem::KIND_IDENTITY_CHANGE, $item->kind);
        $this->assertSame($a5, (int) $item->apkuk_id);
        $this->assertSame([$a5], $item->candidate_apkuk_ids);
        $this->assertFalse(app(ApkukCandidateService::class)->find($a5)->isEligible());

        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_IDENTITY)->assertSessionHasNoErrors();

        $row = DB::table('apkuks')->where('id', $a5)->first();
        $this->assertSame('F.REOG.000005-KOREKSI', $row->no_tiang);
        $this->assertSame($item->legacy_key, $row->legacy_key);
        $this->assertSame($a5, (int) DB::table('work_order_apkuk_targets')->value('apkuk_id'), 'Referensi WO tetap ke baris yang sama.');
        $item->refresh();
        $this->assertSame(ApkukReconciliationItem::RESOLUTION_IDENTITY_CONFIRMED, $item->resolution);
        $this->assertSame($this->admin->id, (int) $item->resolved_by_user_id);
        $this->assertSame('F.REOG.000005', $item->resolution_details['previous']['no_tiang']);
        $this->assertSame(['WO-APKUK-UJI-0002'], $item->resolution_details['work_orders']);

        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* new=0 updated=0 returned=0 pending=0 /', $output);
        $this->assertSame(self::INITIAL_REPORTS + 1, DB::table('apkuks')->count());
        $this->assertSame(DB::table('apkuks')->count(), DB::table('apkuks')->distinct()->count('legacy_key'));
    }

    public function test_pole_change_resolved_as_different_report_inserts_new_row_and_queues_old_one(): void
    {
        $a6 = $this->rowId('A6');
        $this->reports['A6'][5] = 'F.REOG.800006';
        $this->sync();
        $item = ApkukReconciliationItem::query()->open()->sole();

        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_INSERT_AS_NEW)->assertSessionHasNoErrors();

        $newId = (int) DB::table('apkuks')->where('no_tiang', 'F.REOG.800006')->value('id');
        $this->assertNotSame(0, $newId);
        $this->assertSame($newId, $item->refresh()->resolution_details['inserted_apkuk_id']);
        $missing = ApkukReconciliationItem::query()->open()->sole();
        $this->assertSame([ApkukReconciliationItem::KIND_MISSING, $a6], [$missing->kind, (int) $missing->apkuk_id]);
        $candidates = app(ApkukCandidateService::class)->evaluate();
        $this->assertFalse($candidates[$a6]->isEligible());
        $this->assertTrue($candidates[$newId]->isEligible());

        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ new=0 .* pending=1 pending_new=0 /', $output);

        $this->resolve($missing->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED)->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('apkuks')->where('id', $a6)->value('source_missing_at'));
        [, $output] = $this->sync();
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* pending=0 /', $output);
        $this->assertSame(self::INITIAL_REPORTS + 2, DB::table('apkuks')->count(), 'Baris lama tetap tersimpan sebagai histori.');
    }

    public function test_reused_appsheet_id_is_never_paired_by_guessing(): void
    {
        // ID dipakai lagi untuk tiang ketiga sementara laporan lain ber-ID sama masih ada: laporan baru biasa.
        $this->reports['A150-C'] = $this->report('A150', 'F.REOG.950150');
        [, $output] = $this->sync();
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* new=1 .* pending=0 /', $output);

        // Dua laporan A150 hilang dan satu identitas A150 baru muncul: tidak ditebak pasangannya.
        $first = $this->rowId('A150', 'F.REOG.000150');
        $second = $this->rowId('A150', 'F.REOG.900150');
        unset($this->reports['A150'], $this->reports['A150-B']);
        $this->reports['A150-D'] = $this->report('A150', 'F.REOG.990150');
        [$code, $output] = $this->sync();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ new=0 .* pending=3 pending_new=3 .* identity_changes=1/', $output);
        $identity = ApkukReconciliationItem::query()->open()->where('kind', ApkukReconciliationItem::KIND_IDENTITY_CHANGE)->sole();
        $this->assertNull($identity->apkuk_id);
        $this->assertEqualsCanonicalizing([$first, $second], $identity->candidate_apkuk_ids);
        $this->assertSame(2, ApkukReconciliationItem::query()->open()->where('kind', ApkukReconciliationItem::KIND_MISSING)->count());

        // Admin wajib memilih laporan lama; pilihan di luar kandidat ditolak.
        $this->resolve($identity->id, ApkukReconciliationQueue::DECISION_CONFIRM_IDENTITY)->assertSessionHasErrors('apkuk_id');
        $this->resolve($identity->id, ApkukReconciliationQueue::DECISION_CONFIRM_IDENTITY, ['apkuk_id' => $this->rowId('A9')])->assertSessionHasErrors('apkuk_id');
        $this->resolve($identity->id, ApkukReconciliationQueue::DECISION_CONFIRM_IDENTITY, ['apkuk_id' => $second])->assertSessionHasNoErrors();

        $this->assertSame('F.REOG.990150', DB::table('apkuks')->where('id', $second)->value('no_tiang'));
        $this->assertSame(
            ApkukReconciliationItem::RESOLUTION_SUPERSEDED,
            ApkukReconciliationItem::query()->where('kind', ApkukReconciliationItem::KIND_MISSING)->where('apkuk_id', $second)->value('resolution'),
        );
        $sibling = ApkukReconciliationItem::query()->where('kind', ApkukReconciliationItem::KIND_MISSING)->where('apkuk_id', $second)->sole();
        $this->assertSame($this->admin->id, (int) $sibling->resolved_by_user_id);
        $this->assertSame($identity->id, $sibling->resolution_details['resolved_via_item_id']);
        $this->assertSame([$first], ApkukReconciliationItem::query()->open()->pluck('apkuk_id')->map(fn ($id) => (int) $id)->all());

        [, $output] = $this->sync();
        $this->assertMatchesRegularExpression('/ new=0 .* pending=1 pending_new=0 /', $output);
        $this->assertSame(DB::table('apkuks')->count(), DB::table('apkuks')->distinct()->count('legacy_key'));
    }

    public function test_reports_that_reappear_are_resolved_or_restored_automatically(): void
    {
        $a9 = $this->rowId('A9');
        $a10 = $this->rowId('A10');
        $removed = [$this->reports['A9'], $this->reports['A10']];
        unset($this->reports['A9'], $this->reports['A10']);
        $this->sync();
        $history = ApkukReconciliationItem::query()->open()->where('apkuk_id', $a10)->sole();
        $this->resolve($history->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED)->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('apkuks')->where('id', $a10)->value('source_missing_at'));

        [$this->reports['A9'], $this->reports['A10']] = $removed;
        [$code, $output] = $this->sync();

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* new=0 updated=0 returned=1 pending=0 pending_new=0 pending_resolved=1 /', $output);
        $item = ApkukReconciliationItem::query()->where('apkuk_id', $a9)->sole();
        $this->assertSame([ApkukReconciliationItem::STATUS_RESOLVED, ApkukReconciliationItem::RESOLUTION_RETURNED], [$item->status, $item->resolution]);
        $this->assertNull($item->resolved_by_user_id);
        $this->assertSame((int) ApkukSyncRun::query()->max('id'), (int) $item->resolved_run_id);
        $this->assertNull(DB::table('apkuks')->where('id', $a10)->value('source_missing_at'), 'Histori yang muncul kembali dipulihkan.');
        $this->assertSame(['returned_apkuk_ids' => [$a10]], ApkukSyncRun::query()->latest('id')->first()->details, 'ID histori yang dipulihkan tercatat.');
        $this->assertSame(ApkukReconciliationItem::RESOLUTION_CONFIRMED_REMOVED, $history->refresh()->resolution, 'Keputusan arsip lama tetap tercatat.');
        $this->assertSame(1, DB::table('apkuks')->where('source_id', 'A9')->count());
        $this->assertSame(self::INITIAL_REPORTS + 1, DB::table('apkuks')->count());
    }

    public function test_truncated_or_incomplete_sheet_is_rejected_without_any_write(): void
    {
        $before = $this->tableDigest();
        $header = $this->sourceRows()[0];
        $all = array_values($this->reports);
        $scenarios = [
            'di bawah batas minimum' => [array_merge([$header], array_slice($all, 0, 100)), 'SOURCE_VALIDATION', 'kurang dari 2800'],
            // 3.000 dari 3.201 laporan (turun 201 > 5%), ditambah satu laporan baru yang ikut ditolak.
            'terpotong di atas batas minimum' => [array_merge([$header], array_slice($all, 0, 3000), [$this->report('N1', 'F.REOG.700001')]), 'SNAPSHOT_GUARD', 'Kemungkinan Google Sheets terpotong'],
            'kosong' => [[], 'SOURCE_READ', 'APKUK_RAW kosong/tidak lengkap'],
            'kolom bergeser' => [array_merge([array_merge(['No'], $header)], $all), 'SOURCE_READ', 'Header kolom 1 salah'],
        ];

        foreach ($scenarios as $label => [$rows, $stage, $message]) {
            $this->fakeSheets($rows);
            [$code, $output] = $this->sync(publish: false);

            $this->assertSame(1, $code, $label);
            $this->assertStringContainsString($message, $output, $label);
            $this->assertMatchesRegularExpression("/^SYNC_RESULT command=apkuk:sync status=FAILED exit=1 .*stage={$stage} /m", $output, $label);
            $run = ApkukSyncRun::query()->latest('id')->first();
            $this->assertSame([ApkukSyncRun::STATUS_FAILED, $stage, false], [$run->status, $run->failure_stage, $run->snapshot_accepted], $label);
        }

        $this->assertSame($before, $this->tableDigest());
        $this->assertSame(0, ApkukReconciliationItem::query()->count());
        $this->assertSame(0, DB::table('apkuks')->where('source_id', 'N1')->count());
    }

    public function test_hundreds_of_missing_reports_at_once_are_rejected_unless_explicitly_allowed(): void
    {
        foreach (range(1001, 1300) as $i) {
            unset($this->reports["A{$i}"]);
        }
        $before = $this->tableDigest();
        $runs = ApkukSyncRun::query()->count();

        [$code, $output] = $this->sync(['--dry-run' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('DRY RUN: snapshot ini akan DITOLAK pengaman', $output);
        $this->assertSame($runs, ApkukSyncRun::query()->count(), 'Dry-run tidak mencatat apa pun.');

        [$code, $output] = $this->sync();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('300 identitas aktif hilang dari sumber sekaligus, melebihi batas 150 per eksekusi', $output);
        $this->assertStringContainsString('turun dari 3201 (snapshot valid terakhir) menjadi 2901', $output);
        $this->assertSame($before, $this->tableDigest());
        $this->assertSame(0, ApkukReconciliationItem::query()->count());
        $failed = ApkukSyncRun::query()->latest('id')->first();
        $this->assertCount(2, $failed->guard['violations']);
        $this->assertSame(300, $failed->counts['missing']);

        // Penghapusan massal yang memang disetujui: eksekusi manual dengan batas eksplisit.
        // Laporan tetap tidak dihapus; semuanya masuk antrean untuk diputuskan.
        [$code, $output] = $this->sync(['--max-missing' => '300']);
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 .* pending=300 pending_new=300 /', $output);
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
        $this->assertSame(['max_missing' => 300], ApkukSyncRun::query()->latest('id')->first()->guard['overrides']);

        // Cron berikutnya tanpa opsi: yang sudah di antrean tidak dihitung "baru hilang".
        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ pending=300 pending_new=0 /', $output);
    }

    public function test_mass_content_change_is_rejected_as_suspicious(): void
    {
        foreach (range(1, 400) as $i) {
            $this->reports["A{$i}"][9] = ''; // Status terhapus massal (mis. rumus rusak)
        }
        $before = $this->tableDigest();

        [$code, $output] = $this->sync();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('400 laporan berubah isi sekaligus, melebihi batas 321 per eksekusi', $output);
        $this->assertSame($before, $this->tableDigest());
    }

    public function test_repeated_cron_runs_are_idempotent(): void
    {
        unset($this->reports['A1']);
        $this->reports['A2'][5] = 'F.REOG.000002-B';
        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');

        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ new=1 updated=0 returned=0 pending=2 pending_new=2 /', $output);
        $digest = $this->tableDigest();
        $items = ApkukReconciliationItem::query()->orderBy('id')->get(['id', 'kind', 'status', 'open_key', 'apkuk_id', 'legacy_key', 'source_record'])->toArray();

        foreach ([2, 3] as $expectedCount) {
            $this->travel(15)->minutes();
            [$code, $output] = $this->sync();
            $this->assertSame(0, $code, $output);
            $this->assertMatchesRegularExpression('/status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 .* new=0 updated=0 returned=0 pending=2 pending_new=0 pending_resolved=0 /', $output);
            $this->assertSame($digest, $this->tableDigest());
            $this->assertSame($items, ApkukReconciliationItem::query()->orderBy('id')->get(['id', 'kind', 'status', 'open_key', 'apkuk_id', 'legacy_key', 'source_record'])->toArray());
            $this->assertSame([$expectedCount], ApkukReconciliationItem::query()->pluck('detection_count')->unique()->values()->all());
        }

        $this->assertSame(DB::table('apkuks')->count(), DB::table('apkuks')->distinct()->count('legacy_key'));
        $this->assertSame(4, ApkukSyncRun::query()->count());
    }

    public function test_database_failure_during_transaction_rolls_back_everything(): void
    {
        unset($this->reports['A1']);
        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');
        $this->reports['A3'][8] = 7;
        $before = $this->tableDigest();
        DB::statement("CREATE TRIGGER gagal_antrean BEFORE INSERT ON apkuk_reconciliation_items BEGIN SELECT RAISE(ABORT, 'disk penuh (simulasi)'); END");

        [$code, $output] = $this->sync();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('transaksi database dibatalkan, tidak ada data APKUK yang ditulis: ', $output);
        $this->assertStringContainsString('disk penuh (simulasi)', $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=FAILED exit=1 .*stage=DATABASE /m', $output);
        $this->assertSame($before, $this->tableDigest(), 'Laporan baru dan perubahan isi ikut di-rollback.');
        $this->assertSame(0, ApkukReconciliationItem::query()->count());
        $run = ApkukSyncRun::query()->latest('id')->first();
        $this->assertSame([ApkukSyncRun::STATUS_FAILED, ApkukSyncRun::STAGE_DATABASE], [$run->status, $run->failure_stage]);

        // Setelah gangguan database pulih, eksekusi berikutnya menerapkan snapshot yang sama.
        DB::statement('DROP TRIGGER gagal_antrean');
        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/ new=1 updated=1 returned=0 pending=1 pending_new=1 /', $output);
    }

    public function test_work_orders_are_never_modified_and_pending_reports_cannot_get_new_work_orders(): void
    {
        $a3 = $this->rowId('A3');
        $a4 = $this->rowId('A4');
        $this->createWorkOrderTarget('WO-APKUK-UJI-0003', WorkOrderStatus::Ditugaskan, $a3);
        $workOrders = $this->workOrderTables();
        unset($this->reports['A3'], $this->reports['A4']);

        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertSame($workOrders, $this->workOrderTables());

        // Laporan yang menunggu rekonsiliasi tidak dapat menjadi target WO baru.
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $this->travelTo(Carbon::create(2026, 10, 28, 9, 0, 0, 'Asia/Jakarta'));
        try {
            app(ApkukWorkOrderService::class)->createManual($this->admin, ['apkuk_id' => $a4, 'posko_id' => $posko->id, 'work_date' => '2026-10-28', 'shift_code' => 'A']);
            $this->fail('WO untuk laporan yang menunggu rekonsiliasi seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('menunggu rekonsiliasi APKUK', $e->errors()['apkuk_id'][0]);
        }
        $this->assertSame($workOrders, $this->workOrderTables());

        // Konfirmasi hapus laporan yang dirujuk WO aktif wajib dicentang; WO tetap utuh.
        $item = ApkukReconciliationItem::query()->open()->where('apkuk_id', $a3)->sole();
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED)->assertSessionHasErrors('acknowledge_active_wo');
        $this->assertNull(DB::table('apkuks')->where('id', $a3)->value('source_missing_at'));
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED, ['acknowledge_active_wo' => '1'])->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('apkuks')->where('id', $a3)->value('source_missing_at'));
        $this->assertSame($workOrders, $this->workOrderTables());
        $details = $item->refresh()->resolution_details;
        $this->assertSame(['WO-APKUK-UJI-0003'], $details['work_orders']);
        $this->assertTrue($details['active_work_orders_acknowledged']);
    }

    public function test_resolution_requires_authenticated_active_admin_and_is_permanently_recorded(): void
    {
        $a1 = $this->rowId('A1');
        unset($this->reports['A1']);
        $this->sync();
        $item = ApkukReconciliationItem::query()->open()->sole();
        $url = route('admin.apkuk-reconciliation.resolve', $item->id);
        $payload = ['decision' => ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED, 'reason' => 'Laporan ganda dihapus petugas SPS BOS.', 'owner_confirmation' => 'Sdr. Pemilik SPS BOS — WA 28/10 10.15'];

        $this->post($url, $payload)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'POSKO', 'is_active' => true]))->post($url, $payload)->assertForbidden();
        $this->actingAs(User::factory()->inactiveAdmin()->create())->post($url, $payload)->assertForbidden();
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED, ['reason' => 'ok'])->assertSessionHasErrors('reason');
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_INSERT_AS_NEW)->assertSessionHasErrors('decision');
        // Pengarsipan tanpa konfirmasi pemilik data ditolak.
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED, ['owner_confirmation' => ''])->assertSessionHasErrors('owner_confirmation');
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED, ['owner_confirmation' => "Pemilik\nsisipan"])->assertSessionHasErrors('owner_confirmation');
        $this->assertTrue($item->refresh()->isOpen());
        $this->assertNull(DB::table('apkuks')->where('id', $a1)->value('source_missing_at'));

        // Layanan juga menolak akun non-Admin walau dipanggil di luar HTTP.
        try {
            app(ApkukReconciliationQueue::class)->resolve(User::factory()->inactiveAdmin()->create(), $item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED, 'Alasan yang cukup panjang', ownerConfirmation: 'Pemilik SPS BOS');
            $this->fail('Akun nonaktif tidak boleh menyelesaikan antrean.');
        } catch (AuthorizationException) {
        }

        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->resolve($item->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED)->assertSessionHasErrors('item');

        $item->refresh();
        $this->assertSame(ApkukReconciliationItem::RESOLUTION_CONFIRMED_REMOVED, $item->resolution);
        $this->assertSame($this->admin->id, (int) $item->resolved_by_user_id);
        $this->assertSame('Dikonfirmasi petugas SPS BOS, laporan memang dihapus.', $item->resolution_reason);
        $this->assertSame('Sdr. Pemilik SPS BOS — WA 28/10 10.15', $item->owner_confirmation);
        $this->assertNotNull($item->resolved_at);
        $this->assertNull($item->open_key);

        // Histori: baris tidak pernah dihapus dan model apkuks tetap hanya-baca.
        $this->assertSame(self::INITIAL_REPORTS + 1, DB::table('apkuks')->count());
        $this->expectException(LogicException::class);
        Apkuk::query()->findOrFail($a1)->delete();
    }

    public function test_admin_can_confirm_many_missing_reports_at_once_all_or_nothing(): void
    {
        $a3 = $this->rowId('A3');
        $this->createWorkOrderTarget('WO-APKUK-UJI-0005', WorkOrderStatus::Ditugaskan, $a3);
        $workOrders = $this->workOrderTables();
        unset($this->reports['A1'], $this->reports['A2'], $this->reports['A3']);
        $this->sync();
        $ids = ApkukReconciliationItem::query()->open()->orderBy('id')->pluck('id')->all();
        $url = route('admin.apkuk-reconciliation.resolve-many');
        $payload = ['item_ids' => $ids, 'reason' => 'Pembersihan laporan ganda SPS BOS disetujui pemilik data.', 'owner_confirmation' => 'Sdr. Pemilik SPS BOS — WA 28/10 10.15'];

        $this->actingAs($this->admin)->from(route('admin.apkuk-reconciliation.index'))
            ->post($url, ['owner_confirmation' => ''] + $payload + ['acknowledge_active_wo' => '1'])->assertSessionHasErrors('owner_confirmation');

        // Satu laporan dirujuk WO aktif tanpa konfirmasi: tidak ada satu pun yang berubah.
        $this->actingAs($this->admin)->from(route('admin.apkuk-reconciliation.index'))->post($url, $payload)->assertSessionHasErrors('acknowledge_active_wo');
        $this->assertSame(3, ApkukReconciliationItem::query()->open()->count());
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
        $this->actingAs(User::factory()->create(['role' => 'POSKO', 'is_active' => true]))->post($url, $payload + ['acknowledge_active_wo' => '1'])->assertForbidden();

        $this->actingAs($this->admin)->from(route('admin.apkuk-reconciliation.index'))
            ->post($url, $payload + ['acknowledge_active_wo' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '3 laporan diarsipkan atas konfirmasi pemilik data.');

        $this->assertSame(3, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
        $items = ApkukReconciliationItem::query()->whereIn('id', $ids)->get();
        $this->assertSame([ApkukReconciliationItem::RESOLUTION_CONFIRMED_REMOVED], $items->pluck('resolution')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $items->pluck('resolved_by_user_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame([3], $items->map(fn (ApkukReconciliationItem $item) => $item->resolution_details['batch_size'])->unique()->values()->all());
        $this->assertSame(['Sdr. Pemilik SPS BOS — WA 28/10 10.15'], $items->pluck('owner_confirmation')->unique()->values()->all());
        $this->assertSame($workOrders, $this->workOrderTables());
        $audit = AuditLog::query()->where('entity_type', 'apkuk_reconciliation_item')->whereIn('entity_id', array_map('strval', $ids))->get();
        $this->assertCount(3, $audit);
        $this->assertSame([AuditLog::ACTION_ARCHIVED], $audit->pluck('action')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $audit->pluck('actor_user_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $this->actingAs($this->admin)->from(route('admin.apkuk-reconciliation.index'))->post($url, $payload)->assertSessionHasErrors('item');
        [, $output] = $this->sync();
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* pending=0 /', $output);
    }

    public function test_reconcile_apply_closes_matching_queue_items(): void
    {
        unset($this->reports['A1'], $this->reports['A2']);
        $this->sync();
        $this->assertSame(2, ApkukReconciliationItem::query()->open()->count());

        [, $output] = $this->artisan2('apkuk:reconcile');
        preg_match('/RECONCILE_PLAN id=([0-9a-f]{16})/', $output, $m);
        $this->app->instance(ConsoleAdminAuthenticator::class, new class extends ConsoleAdminAuthenticator
        {
            protected function readPassword(Command $command, string $username): ?string
            {
                return 'password';
            }
        });
        [$code, $output] = $this->artisan2('apkuk:reconcile', [
            '--apply' => true, '--plan' => $m[1], '--expect-missing' => '2', '--admin' => 'ADMIN_REKON',
            '--approved-by' => 'Supervisor Har', '--reason' => 'Penghapusan disetujui pemilik data SPS BOS.',
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Antrean rekonsiliasi yang ikut diselesaikan: 2.', $output);
        $reconciliationId = (int) DB::table('apkuk_reconciliations')->value('id');
        $items = ApkukReconciliationItem::query()->get();
        $this->assertSame([ApkukReconciliationItem::RESOLUTION_RECONCILED], $items->pluck('resolution')->unique()->values()->all());
        $this->assertSame([$reconciliationId], $items->pluck('reconciliation_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame([$this->admin->id], $items->pluck('resolved_by_user_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame($this->admin->id, (int) DB::table('apkuk_reconciliations')->value('approved_by_user_id'));
        $audit = AuditLog::query()->where('entity_type', 'apkuk_reconciliation')->sole();
        $this->assertSame([AuditLog::ACTION_ARCHIVED, $this->admin->id, 'Supervisor Har'], [$audit->action, (int) $audit->actor_user_id, $audit->after['owner_confirmation']]);

        [$code, $output] = $this->sync();
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* pending=0 /', $output);
    }

    public function test_pending_report_is_never_archived_automatically_however_long_it_is_missing(): void
    {
        $a1 = $this->rowId('A1');
        $removed = $this->reports['A1'];
        unset($this->reports['A1']);

        // Hilang lebih dari 60 hari dan puluhan siklus cron: tetap pending, tidak menjadi histori.
        foreach (range(1, 6) as $cycle) {
            [$code, $output] = $this->sync();
            $this->assertSame(0, $code, $output);
            $this->travel(12)->days();
        }

        $item = ApkukReconciliationItem::query()->open()->sole();
        $this->assertSame([$a1, 6], [(int) $item->apkuk_id, $item->detection_count]);
        $this->assertNull(DB::table('apkuks')->where('id', $a1)->value('source_missing_at'));
        $this->assertSame(1, Apkuk::query()->awaitingReconciliation()->count());
        $this->assertSame(0, Apkuk::query()->sourceHistory()->count());

        // Laporan yang sedang diedit/dipindahkan lalu kembali tetap pulih tanpa duplikasi.
        $this->reports['A1'] = $removed;
        [, $output] = $this->sync();
        $this->assertMatchesRegularExpression('/status=SUCCESS exit=0 .* new=0 .* pending=0 pending_new=0 pending_resolved=1 /', $output);
        $this->assertSame(1, DB::table('apkuks')->where('source_id', 'A1')->count());
        $this->assertSame(ApkukReconciliationItem::RESOLUTION_RETURNED, $item->refresh()->resolution);
    }

    public function test_source_state_helper_matches_model_scopes_for_query_builder_consumers(): void
    {
        unset($this->reports['A1'], $this->reports['A2']);
        $this->sync();
        $archive = ApkukReconciliationItem::query()->open()->where('apkuk_id', $this->rowId('A2'))->sole();
        $this->resolve($archive->id, ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED)->assertSessionHasNoErrors();

        // Dipakai halaman lain (dashboard, analitik) yang membaca apkuks lewat query builder.
        $this->assertSame(self::INITIAL_REPORTS - 1, ApkukSourceState::active(DB::table('apkuks'))->count());
        $this->assertSame(1, ApkukSourceState::pending(DB::table('apkuks'))->count());
        $this->assertSame(1, ApkukSourceState::history(DB::table('apkuks'))->count());
        $this->assertSame(
            [Apkuk::query()->sourceActive()->count(), Apkuk::query()->awaitingReconciliation()->count(), Apkuk::query()->sourceHistory()->count()],
            [self::INITIAL_REPORTS - 1, 1, 1],
        );
        $this->assertSame(1, ApkukSourceState::pending(DB::table('apkuks as a'), 'a')->where('a.source_id', 'A1')->count(), 'Alias tabel didukung.');
    }

    public function test_reconcile_plan_is_not_invalidated_by_routine_sync_timestamps(): void
    {
        unset($this->reports['A1']);
        $this->sync();
        [, $first] = $this->artisan2('apkuk:reconcile');
        $this->travel(15)->minutes();
        $this->sync(); // cron berikutnya hanya memperbarui last_synced_at
        [, $second] = $this->artisan2('apkuk:reconcile');

        preg_match('/RECONCILE_PLAN id=([0-9a-f]{16})/', $first, $a);
        preg_match('/RECONCILE_PLAN id=([0-9a-f]{16})/', $second, $b);
        $this->assertSame($a[1], $b[1]);
    }

    public function test_admin_page_lists_queue_and_sync_history(): void
    {
        $this->withoutVite();
        $this->createWorkOrderTarget('WO-APKUK-UJI-0004', WorkOrderStatus::Ditugaskan, $this->rowId('A1'));
        unset($this->reports['A1']);
        $this->reports['A2'][5] = 'F.REOG.000002-B';
        $this->sync();

        $this->actingAs($this->admin)->get(route('admin.apkuk-reconciliation.index'))
            ->assertOk()
            ->assertSee('Antrean Rekonsiliasi APKUK')
            ->assertSee('Hilang dari sumber')
            ->assertSee('Dugaan perubahan identitas')
            ->assertSee('F.REOG.000002-B')
            ->assertSee('WO-APKUK-UJI-0004')
            ->assertSee('name="acknowledge_active_wo"', false)
            ->assertSee('Berhasil, ada antrean rekonsiliasi');
        $this->actingAs($this->admin)->get(route('admin.apkuk-reconciliation.index', ['jenis' => 'IDENTITY_CHANGE']))
            ->assertOk()->assertSee('F.REOG.000002-B')->assertDontSee('name="acknowledge_active_wo"', false);
        $this->actingAs($this->admin)->get(route('admin.maps.index'))->assertOk()->assertSee(route('admin.apkuk-reconciliation.index'));

        [$code, $output] = $this->artisan2('apkuk:pending', ['--export' => $export = tempnam(sys_get_temp_dir(), 'pending').'.csv']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Antrean terbuka: 2 (hilang: 1, dugaan perubahan identitas: 1).', $output);
        $csv = array_map('str_getcsv', file($export, FILE_IGNORE_NEW_LINES));
        @unlink($export);
        $this->assertCount(3, $csv);
        $this->assertStringContainsString('WO-APKUK-UJI-0004(AKTIF)', implode(',', $csv[1]).implode(',', $csv[2]));
    }

    public function test_sync_refuses_to_run_before_queue_migration(): void
    {
        Schema::drop('apkuk_reconciliation_items');
        $before = $this->tableDigest();

        [$code, $output] = $this->sync();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Jalankan migration additive 2026_10_28_100001', $output);
        $this->assertSame($before, $this->tableDigest());
        $this->assertSame([], ApkukReconciliationItem::openApkukIds(), 'Kandidat WO tetap terbaca tanpa tabel antrean.');
    }

    public function test_new_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_10_28_100001_create_apkuk_sync_runs_and_reconciliation_items.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('apkuk_reconciliation_items'));
        $this->assertFalse(Schema::hasTable('apkuk_sync_runs'));
        $this->assertSame(self::INITIAL_REPORTS + 1, DB::table('apkuks')->count(), 'Rollback tidak menyentuh apkuks.');

        $migration->up();
        $this->assertTrue(Schema::hasTable('apkuk_reconciliation_items'));
        $this->assertTrue(Schema::hasColumn('apkuk_sync_runs', 'snapshot_accepted'));
        $this->assertTrue(Schema::hasColumn('apkuk_reconciliation_items', 'owner_confirmation'));

        $admin = require database_path('migrations/2026_10_28_100002_add_approved_by_user_id_to_apkuk_reconciliations.php');
        $admin->down();
        $this->assertFalse(Schema::hasColumn('apkuk_reconciliations', 'approved_by_user_id'));
        $this->assertTrue(Schema::hasColumn('apkuk_reconciliations', 'approved_by'), 'Kolom lama tidak tersentuh.');
        $admin->up();
        $this->assertTrue(Schema::hasColumn('apkuk_reconciliations', 'approved_by_user_id'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function sync(array $options = [], bool $publish = true): array
    {
        if ($publish) {
            $this->fakeSheets($this->sourceRows());
        }

        return $this->artisan2('apkuk:sync', $options);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function resolve(int $itemId, string $decision, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.apkuk-reconciliation.index'))
            ->post(route('admin.apkuk-reconciliation.resolve', $itemId), $extra + [
                'decision' => $decision,
                'reason' => 'Dikonfirmasi petugas SPS BOS, laporan memang dihapus.',
                'owner_confirmation' => 'Sdr. Pemilik SPS BOS — WA 28/10 10.15',
            ]);
    }

    private function rowId(string $sourceId, ?string $pole = null): int
    {
        return (int) DB::table('apkuks')->where('source_id', $sourceId)
            ->when($pole !== null, fn ($query) => $query->where('no_tiang', $pole))
            ->orderBy('id')->value('id');
    }

    private function createWorkOrderTarget(string $code, WorkOrderStatus $status, int $apkukId): void
    {
        $wo = WorkOrder::create([
            'code' => $code, 'team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL, 'status' => $status, 'work_date' => '2026-10-28',
        ]);
        $pole = DB::table('apkuks')->where('id', $apkukId)->value('no_tiang');
        DB::table('work_order_apkuk_targets')->insert([
            'id' => (string) Str::uuid(), 'work_order_id' => $wo->id, 'apkuk_id' => $apkukId,
            'target_key' => "REOG|{$pole}", 'active_target_key' => $status->isActive() ? "REOG|{$pole}" : null,
            'no_tiang' => $pole, 'source_kurang_bh' => 2, 'target_quantity' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function workOrderTables(): array
    {
        return array_map(
            fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            ['work_orders', 'work_order_apkuk_targets', 'work_order_status_logs'],
        );
    }

    /**
     * Sidik isi apkuks tanpa last_synced_at (cap waktu terakhir terlihat di sumber).
     */
    private function tableDigest(): string
    {
        $hash = hash_init('sha256');
        foreach (DB::table('apkuks')->orderBy('id')->cursor() as $row) {
            $row = (array) $row;
            unset($row['last_synced_at']);
            hash_update($hash, json_encode($row));
        }

        return hash_final($hash).'#'.DB::table('apkuks')->count();
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function artisan2(string $command, array $parameters = []): array
    {
        $output = new BufferedOutput;
        $code = Artisan::call($command, $parameters, $output);

        return [$code, $output->fetch()];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function sourceRows(): array
    {
        return array_merge([[
            'ID', 'Tanggal', 'Petugas', 'Penyulang', 'Section',
            'No. Tiang/Gardu', 'Naik Dari', 'Terpasang bh',
            'Kurang bh', 'Status', 'Koordinat', 'Foto',
        ]], array_values($this->reports));
    }

    /**
     * @return array<int, mixed>
     */
    private function report(string $id, string $pole, int $tanggal = 45950): array
    {
        return [$id, $tanggal, 'Petugas Uji', 'REOG', 'SEC 1', $pole, 'TR', 1, 2, 'BELUM EKSEKUSI', '-5.2,105.7', null];
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function fakeSheets(array $rows): void
    {
        $this->app->instance(GoogleSheetValues::class, new class($rows) extends GoogleSheetValues
        {
            public function __construct(private array $rows) {}

            public function get(string $credentialsPath, string $spreadsheetId, string $range, array $params = []): array
            {
                return $this->rows;
            }
        });
    }
}
