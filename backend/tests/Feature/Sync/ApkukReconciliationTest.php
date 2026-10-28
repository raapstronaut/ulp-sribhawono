<?php

namespace Tests\Feature\Sync;

use App\Console\Support\ConsoleAdminAuthenticator;
use App\Enums\WorkOrderStatus;
use App\Models\ApkukReconciliationItem;
use App\Models\Penyulang;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Apkuk\ApkukReconciliationPlan;
use App\Services\Apkuk\ApkukReconciliationPlanner;
use App\Services\Apkuk\ApkukSourceReader;
use App\Services\Apkuk\ApkukSourceSnapshot;
use App\Services\ApkukCandidateService;
use App\Services\GoogleSheetValues;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Rekonsiliasi APKUK: dry-run read-only, persetujuan eksplisit, penandaan
 * source_missing_at tanpa hard-delete, keamanan WO, dan pemulihan otomatis.
 * Google Sheets dipalsukan; database SQLite in-memory.
 */
class ApkukReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const INITIAL_REPORTS = 2810;

    private string $credentialsPath;

    /** @var array<int, array<int, mixed>> */
    private array $reports = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Apply wajib Admin terautentikasi; kata sandi factory = "password".
        $this->admin = User::factory()->admin()->create(['username' => 'ADMIN_REKON']);
        $this->typePassword('password');

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'apkuk-cred');
        file_put_contents($this->credentialsPath, '{}');
        config([
            'services.google_sheets.credentials_path' => $this->credentialsPath,
            'services.google_sheets.spreadsheet_id' => 'sheet-test',
        ]);

        foreach (range(1, self::INITIAL_REPORTS) as $i) {
            $this->reports["A{$i}"] = $this->report("A{$i}", sprintf('F.REOG.%06d', $i));
        }
        // ID dipakai ulang untuk tiang lain (pola A150) dan tiang dengan dua laporan berbeda ID.
        $this->reports['A150-B'] = $this->report('A150', 'F.REOG.900150');
        $this->reports['B1'] = $this->report('B1', 'F.REOG.800001', tanggal: 45900);
        $this->reports['B2'] = $this->report('B2', 'F.REOG.800001', tanggal: 45950);

        $this->publishSource();
        [$code, $output] = $this->artisan2('apkuk:sync');
        $this->assertSame(0, $code, $output);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);
        // PHPUnit menyimpan objek test sampai suite selesai; fixture ribuan baris dilepas di sini.
        $this->reports = [];

        parent::tearDown();
    }

    public function test_dry_run_reports_differences_and_writes_nothing(): void
    {
        $this->removeReports(['A1', 'A2', 'A3', 'A150', 'B1']);
        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');
        $this->reports['N2'] = $this->report('N2', 'F.REOG.700002');
        // Laporan ganda: ID berbeda, isi sama dengan A20.
        $this->reports['DUP'] = $this->report('DUP20', 'F.REOG.000020');
        $this->publishSource();
        $before = $this->apkukTable();
        $export = tempnam(sys_get_temp_dir(), 'apkuk-export').'.csv';

        [$code, $output] = $this->artisan2('apkuk:reconcile', ['--export' => $export]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('DRY-RUN — database tidak diubah', $output);
        $this->assertMatchesRegularExpression('/^RECONCILE_PLAN id=[0-9a-f]{16} missing=5 new=3 returning=0 wo_refs=0 active_wo_refs=0 eligible_removed=0 newly_eligible=0\r?$/m', $output);
        $this->assertStringContainsString('(ID DUP20)', $output); // bagian "kemungkinan laporan ganda"
        $this->assertStringContainsString('apkuk:reconcile --apply --plan=', $output);
        $this->assertSame($before, $this->apkukTable(), 'Dry-run tidak boleh mengubah apkuks.');
        $this->assertSame(0, DB::table('apkuk_reconciliations')->count());

        $csv = array_map('str_getcsv', file($export, FILE_IGNORE_NEW_LINES));
        @unlink($export);
        $this->assertSame('kategori', $csv[0][0]);
        $this->assertEquals([
            ApkukReconciliationPlan::CATEGORY_TIDAK_ADA => 3,               // A1, A2, A3
            ApkukReconciliationPlan::CATEGORY_ID_DIPAKAI_TIANG_LAIN => 1,   // A150 tiang asal hilang, A150 lain masih ada
            ApkukReconciliationPlan::CATEGORY_TIANG_MASIH_ADA => 1,         // B1 hilang, B2 di tiang sama masih ada
        ], array_count_values(array_column(array_slice($csv, 1), 0)));

        // Sync rutin tidak lagi gagal: laporan baru masuk, yang hilang hanya masuk antrean
        // (tetap aktif, tidak ditandai) sampai diputuskan Admin atau lewat rekonsiliasi.
        [$code, $output] = $this->artisan2('apkuk:sync');
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 .* new=3 .* pending=5 pending_new=5 /m', $output);
        $this->assertSame((int) explode('#', $before)[1] + 3, DB::table('apkuks')->count());
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
        $this->assertSame(5, DB::table('apkuk_reconciliation_items')->where('status', 'OPEN')->where('kind', 'MISSING')->count());
    }

    public function test_apply_requires_complete_and_matching_approval(): void
    {
        $this->removeReports(['A1', 'A2']);
        $this->publishSource();
        $plan = $this->planId();
        $before = $this->apkukTable();

        $attempts = [
            'tanpa persetujuan' => ['--apply' => true],
            'kode rencana salah' => $this->approval('0000000000000000', 2),
            'jumlah salah' => $this->approval($plan, 3),
            'alasan terlalu pendek' => ['--reason' => 'ok'] + $this->approval($plan, 2),
            'penyetuju kosong' => ['--approved-by' => ''] + $this->approval($plan, 2),
        ];
        foreach ($attempts as $label => $options) {
            [$code, $output] = $this->artisan2('apkuk:reconcile', $options);
            $this->assertSame(1, $code, "{$label}: {$output}");
            $this->assertStringContainsString('Tidak ada perubahan', $output, $label);
        }

        $this->assertSame($before, $this->apkukTable());
        $this->assertSame(0, DB::table('apkuk_reconciliations')->count());
    }

    public function test_approved_apply_marks_only_missing_rows_then_sync_runs_normally_without_duplicates(): void
    {
        $this->removeReports(['A1', 'A2', 'A3']);
        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');
        $this->publishSource();
        $plan = $this->planId();
        $totalBefore = DB::table('apkuks')->count();
        $missingBefore = DB::table('apkuks')->whereIn('source_id', ['A1', 'A2', 'A3'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 3));

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression("/^RECONCILE_APPLIED plan={$plan} marked=3 /m", $output);
        $this->assertSame($totalBefore, DB::table('apkuks')->count(), 'Tidak boleh ada baris yang dihapus.');
        $this->assertSame(3, DB::table('apkuks')->whereNotNull('source_missing_at')->count());

        // Hanya source_missing_at dan updated_at yang berubah pada baris yang ditandai.
        $missingAfter = DB::table('apkuks')->whereIn('source_id', ['A1', 'A2', 'A3'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        foreach ($missingBefore as $i => $row) {
            $this->assertNotNull($missingAfter[$i]['source_missing_at']);
            $this->assertSame(
                array_diff_key($row, array_flip(['source_missing_at', 'updated_at'])),
                array_diff_key($missingAfter[$i], array_flip(['source_missing_at', 'updated_at'])),
            );
        }

        $audit = DB::table('apkuk_reconciliations')->sole();
        $this->assertSame($plan, $audit->plan_id);
        $this->assertSame('MARK_SOURCE_MISSING', $audit->action);
        $this->assertSame('Supervisor Har, ULP Sribawono', $audit->approved_by);
        $this->assertSame(3, (int) $audit->marked_count);
        $this->assertCount(3, json_decode($audit->legacy_keys, true));

        // Sync rutin kembali normal: laporan baru masuk, tanpa duplikat identitas.
        [$code, $output] = $this->artisan2('apkuk:sync');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('bertambah: 1', $output);
        $this->assertSame($totalBefore + 1, DB::table('apkuks')->count());
        $this->assertSame(DB::table('apkuks')->count(), DB::table('apkuks')->distinct()->count('legacy_key'));

        // Rekonsiliasi berikutnya tidak menemukan apa pun; menjalankan ulang aman.
        [$code, $output] = $this->artisan2('apkuk:reconcile');
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/RECONCILE_PLAN id=[0-9a-f]{16} missing=0 new=0 returning=0/', $output);
    }

    public function test_report_that_reappears_in_source_is_restored_by_sync(): void
    {
        $this->removeReports(['A5']);
        $removed = $this->report('A5', sprintf('F.REOG.%06d', 5));
        $this->publishSource();
        $this->artisan2('apkuk:reconcile', $this->approval($this->planId(), 1));
        $this->assertNotNull(DB::table('apkuks')->where('source_id', 'A5')->value('source_missing_at'));

        $this->reports['A5'] = $removed;
        $this->publishSource();

        [, $output] = $this->artisan2('apkuk:reconcile');
        $this->assertMatchesRegularExpression('/RECONCILE_PLAN id=[0-9a-f]{16} missing=0 new=0 returning=1/', $output);

        [$code, $output] = $this->artisan2('apkuk:sync');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('dipulihkan dari source_missing_at: 1', $output);
        $this->assertSame(1, DB::table('apkuks')->where('source_id', 'A5')->count(), 'Tidak boleh ada baris ganda.');
        $this->assertNull(DB::table('apkuks')->where('source_id', 'A5')->value('source_missing_at'));
    }

    public function test_active_work_order_reference_needs_acknowledgement_and_work_order_history_is_untouched(): void
    {
        $apkukId = (int) DB::table('apkuks')->where('source_id', 'A3')->value('id');
        $wo = WorkOrder::create([
            'code' => 'WO-APKUK-TEST-0001', 'team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL, 'status' => WorkOrderStatus::Ditugaskan, 'work_date' => '2026-10-27',
        ]);
        DB::table('work_order_apkuk_targets')->insert([
            'id' => (string) Str::uuid(), 'work_order_id' => $wo->id, 'apkuk_id' => $apkukId,
            'target_key' => 'REOG|F.REOG.000003', 'active_target_key' => 'REOG|F.REOG.000003', 'no_tiang' => 'F.REOG.000003',
            'source_kurang_bh' => 2, 'target_quantity' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $woBefore = [(array) DB::table('work_orders')->where('id', $wo->id)->first(), DB::table('work_order_apkuk_targets')->get()->map(fn ($r) => (array) $r)->all()];

        $this->removeReports(['A3']);
        $this->publishSource();
        [, $output] = $this->artisan2('apkuk:reconcile');
        $this->assertStringContainsString('WO-APKUK-TEST-0001 | DITUGASKAN | apkuk #'.$apkukId.' | AKTIF', $output);
        $this->assertStringContainsString('--allow-active-wo', $output);
        $plan = $this->planId();

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 1));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('WO aktif merujuk laporan', $output);
        $this->assertNull(DB::table('apkuks')->where('id', $apkukId)->value('source_missing_at'));

        [$code, $output] = $this->artisan2('apkuk:reconcile', ['--allow-active-wo' => true] + $this->approval($plan, 1));
        $this->assertSame(0, $code, $output);
        $this->assertNotNull(DB::table('apkuks')->where('id', $apkukId)->value('source_missing_at'));
        $this->assertSame($woBefore, [(array) DB::table('work_orders')->where('id', $wo->id)->first(), DB::table('work_order_apkuk_targets')->get()->map(fn ($r) => (array) $r)->all()]);
        $this->assertSame(0, DB::table('work_order_status_logs')->count());
    }

    public function test_reconcile_refuses_empty_truncated_or_colliding_source_even_with_approval(): void
    {
        $before = $this->apkukTable();
        $header = $this->sourceRows()[0];
        $scenarios = [
            'kosong' => [[], 'APKUK_RAW kosong/tidak lengkap'],
            'terpotong' => [array_merge([$header], array_slice(array_values($this->reports), 0, 100)), 'kurang dari 2800 laporan'],
            'identitas ganda' => [array_merge($this->sourceRows(), [$this->report('A7', 'F.REOG.000007')]), 'kombinasi ID + tiang terulang'],
        ];

        foreach ($scenarios as $label => [$rows, $message]) {
            $this->fakeSheets($rows);
            [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval('0000000000000000', 0));
            $this->assertSame(1, $code, $label);
            $this->assertStringContainsString($message, $output, $label);
        }

        $this->assertSame($before, $this->apkukTable());
        $this->assertSame(0, DB::table('apkuk_reconciliations')->count());
    }

    public function test_plan_that_changes_after_approval_is_rejected(): void
    {
        $this->removeReports(['A1', 'A2']);
        $this->publishSource();
        $plan = $this->planId();

        // Sumber berubah lagi sebelum apply: rencana yang disetujui tidak berlaku.
        $this->removeReports(['A9']);
        $this->publishSource();

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 2));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Rencana berubah sejak disetujui', $output);
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
    }

    public function test_edited_report_content_invalidates_previous_approval(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        [$plan, $parts] = $this->planWithParts();

        // Laporan lain (bukan yang hilang) diedit di sumber: daftar hilang sama, persetujuan tetap batal.
        $this->reports['A50'][8] = 5; // Kurang bh
        $this->publishSource();
        [$newPlan, $newParts] = $this->planWithParts();
        $this->assertNotSame($plan, $newPlan);
        $this->assertNotSame($parts['isi sumber'], $newParts['isi sumber']);
        $this->assertSame($parts['identitas hilang'], $newParts['identitas hilang']);

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 1));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Rencana berubah sejak disetujui', $output);
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
        $this->assertSame(0, DB::table('apkuk_reconciliations')->count());
    }

    public function test_new_report_in_source_invalidates_previous_approval(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        $plan = $this->planId();

        $this->reports['N1'] = $this->report('N1', 'F.REOG.700001');
        $this->publishSource();

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 1));
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Rencana berubah sejak disetujui', $output);
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());

        // Persetujuan baru atas snapshot terbaru tetap dapat diterapkan.
        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($this->planId(), 1));
        $this->assertSame(0, $code, $output);
        $this->assertSame(1, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
    }

    public function test_database_or_work_order_change_after_approval_invalidates_plan(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        $missingId = (int) DB::table('apkuks')->where('source_id', 'A1')->value('id');
        $changes = [
            'tabel apkuks' => fn () => DB::table('apkuks')->where('source_id', 'A60')->update(['kurang_bh' => 9]),
            'referensi WO langsung' => fn () => $this->createWorkOrderTarget('WO-APKUK-TEST-0002', WorkOrderStatus::Selesai, $missingId),
        ];

        foreach ($changes as $label => $change) {
            [$plan, $parts] = $this->planWithParts();
            $change();
            [, $newParts] = $this->planWithParts();
            $this->assertNotSame($parts[$label], $newParts[$label], $label);
            $this->assertSame($parts['isi sumber'], $newParts['isi sumber'], $label);

            [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 1));
            $this->assertSame(1, $code, $label);
            $this->assertStringContainsString('Rencana berubah sejak disetujui', $output, $label);
            $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count(), $label);
        }
    }

    public function test_change_during_apply_is_detected_inside_transaction_and_rolls_back(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        $missingId = (int) DB::table('apkuks')->where('source_id', 'A1')->value('id');
        $plan = $this->planId();
        $before = $this->apkukTable();

        // Penulis lain bekerja tepat setelah validasi awal, sebelum tabel dikunci.
        $concurrent = [
            'referensi WO langsung' => fn () => $this->createWorkOrderTarget('WO-APKUK-TEST-0003', WorkOrderStatus::Ditugaskan, $missingId),
            'tabel apkuks' => fn () => DB::table('apkuks')->where('id', $missingId)->update(['source_missing_at' => now()]),
        ];

        foreach ($concurrent as $label => $change) {
            DB::beginTransaction();
            $this->interceptPlanner($change);

            [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 1));

            $this->assertSame(1, $code, "{$label}: {$output}");
            $this->assertStringContainsString('transaksi di-rollback. Tidak ada perubahan', $output, $label);
            $this->assertMatchesRegularExpression("/data berubah sejak validasi \\([^)]*{$label}[^)]*\\)/", $output, $label);
            $this->assertSame(0, DB::table('apkuk_reconciliations')->count(), $label);
            DB::rollBack(); // batalkan perubahan "penulis lain" untuk skenario berikutnya
            $this->app->forgetInstance(ApkukReconciliationPlanner::class);
        }

        $this->assertSame($before, $this->apkukTable());
    }

    public function test_approved_by_is_audit_metadata_only(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        $plan = $this->planId();

        foreach (["Supervisor\nRECONCILE_APPLIED palsu", str_repeat('x', 121)] as $approvedBy) {
            [$code, $output] = $this->artisan2('apkuk:reconcile', ['--approved-by' => $approvedBy] + $this->approval($plan, 1));
            $this->assertSame(1, $code);
            $this->assertStringContainsString('tanpa karakter kontrol. Tidak ada perubahan', $output);
        }
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());

        // Konfirmasi pemilik data tidak memengaruhi kode rencana dan tidak dicocokkan dengan daftar
        // mana pun: kewenangan berasal dari akun Admin terautentikasi, teks konfirmasi hanya dicatat.
        [$code, $output] = $this->artisan2('apkuk:reconcile', ['--approved-by' => 'Nama Bebas'] + $this->approval($plan, 1));

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('kewenangan apply berasal dari akun Admin terautentikasi (--admin)', $output);
        $this->assertStringContainsString('Disetujui Admin terautentikasi: ADMIN_REKON (akun #'.$this->admin->id.')', $output);
        $audit = DB::table('apkuk_reconciliations')->sole();
        $this->assertSame('Nama Bebas', $audit->approved_by);
        $this->assertSame($this->admin->id, (int) $audit->approved_by_user_id);
        $this->assertNull($audit->database_user); // SQLite; di PostgreSQL berisi current_user
        $this->assertSame($plan, $audit->plan_id);
        $this->assertStringStartsWith($plan, $audit->fingerprint);
        $this->assertSame(
            ['source', 'missing', 'database', 'work_orders', 'impact'],
            array_keys(json_decode($audit->fingerprint_parts, true)),
        );
    }

    public function test_apply_requires_an_authenticated_active_admin(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        $plan = $this->planId();
        $attempts = [
            'tanpa --admin' => [['--admin' => ''], 'password', '--admin wajib diisi'],
            'kata sandi salah' => [[], 'salah-sekali', 'Autentikasi Admin gagal'],
            'username tidak ada' => [['--admin' => 'BUKAN_ADMIN'], 'password', 'Autentikasi Admin gagal'],
            'tanpa sesi interaktif' => [[], null, 'Kata sandi Admin wajib dimasukkan secara interaktif'],
        ];
        User::factory()->inactiveAdmin()->create(['username' => 'ADMIN_NONAKTIF']);
        User::factory()->create(['username' => 'POSKO_UJI', 'role' => 'POSKO', 'is_active' => true]);
        $attempts['admin nonaktif'] = [['--admin' => 'admin_nonaktif'], 'password', 'Autentikasi Admin gagal'];
        $attempts['bukan ADMIN'] = [['--admin' => 'POSKO_UJI'], 'password', 'Autentikasi Admin gagal'];

        foreach ($attempts as $label => [$options, $password, $message]) {
            $this->typePassword($password);
            [$code, $output] = $this->artisan2('apkuk:reconcile', $options + $this->approval($plan, 1));
            $this->assertSame(1, $code, $label);
            $this->assertStringContainsString($message, $output, $label);
            $this->assertStringContainsString('Tidak ada perubahan', $output, $label);
        }
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
        $this->assertSame(0, DB::table('apkuk_reconciliations')->count());

        // Username tidak peka huruf besar/kecil, seperti login Web Admin.
        $this->typePassword('password');
        [$code, $output] = $this->artisan2('apkuk:reconcile', ['--admin' => 'admin_rekon'] + $this->approval($plan, 1));
        $this->assertSame(0, $code, $output);
    }

    public function test_apply_records_admin_and_owner_confirmation_on_queue_items(): void
    {
        $this->removeReports(['A1', 'A2']);
        $this->publishSource();
        $this->artisan2('apkuk:sync');
        $this->assertSame(2, ApkukReconciliationItem::query()->open()->count());

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($this->planId(), 2));

        $this->assertSame(0, $code, $output);
        $items = ApkukReconciliationItem::query()->get();
        $this->assertSame([ApkukReconciliationItem::RESOLUTION_RECONCILED], $items->pluck('resolution')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $items->pluck('resolved_by_user_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame(['Supervisor Har, ULP Sribawono'], $items->pluck('owner_confirmation')->unique()->values()->all());
    }

    public function test_direct_and_indirect_work_order_impact_are_reported_separately(): void
    {
        // Master penyulang membuat laporan dinilai sebagai kandidat WO.
        Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);
        $a7 = (int) DB::table('apkuks')->where('source_id', 'A7')->value('id');
        $this->createWorkOrderTarget('WO-APKUK-TEST-0004', WorkOrderStatus::Selesai, $a7);
        $woBefore = $this->workOrderTables();
        $eligibleBefore = app(ApkukCandidateService::class)->summary(app(ApkukCandidateService::class)->evaluate())['eligible'];

        // B2 laporan terbaru tiang F.REOG.800001; B1 laporan lama di tiang yang sama.
        $this->removeReports(['A7', 'B2']);
        $this->publishSource();
        $export = tempnam(sys_get_temp_dir(), 'apkuk-export').'.csv';
        [$code, $output] = $this->artisan2('apkuk:reconcile', ['--export' => $export]);

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/^RECONCILE_PLAN id=[0-9a-f]{16} missing=2 new=0 returning=0 wo_refs=1 active_wo_refs=0 eligible_removed=2 newly_eligible=1\r?$/m', $output);
        $this->assertStringContainsString('Dampak langsung: 1 target WO merujuk laporan yang akan ditandai (0 WO aktif)', $output);
        $this->assertStringContainsString("WO-APKUK-TEST-0004 | SELESAI | apkuk #{$a7}", $output);
        $this->assertStringContainsString('ID B2 | Tiang F.REOG.800001 | kurang 2 | layak -> keluar dari daftar', $output);
        $this->assertStringContainsString('ID B1 | Tiang F.REOG.800001 | kurang 2 | ADA_LAPORAN_LEBIH_BARU -> layak', $output);
        $this->assertMatchesRegularExpression('/\|\s*Kandidat WO APKUK layak dipilih\s*\|\s*'.$eligibleBefore.'\s*\|\s*'.($eligibleBefore - 1).'\s*\|/', $output);

        $csv = array_map('str_getcsv', file($export, FILE_IGNORE_NEW_LINES));
        @unlink($export);
        $rows = array_column(array_map(fn (array $row) => array_combine($csv[0], $row), array_slice($csv, 1)), null, 'source_id');
        $this->assertSame(['YA', 'WO-APKUK-TEST-0004'], [$rows['A7']['kandidat_wo_layak'], $rows['A7']['referensi_wo']]);
        $this->assertSame(['YA', ''], [$rows['B2']['kandidat_wo_layak'], $rows['B2']['referensi_wo']]);

        // WO non-aktif tidak memerlukan --allow-active-wo; setelah apply, kandidat sesuai simulasi.
        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($this->planId(), 2));
        $this->assertSame(0, $code, $output);

        $candidates = app(ApkukCandidateService::class)->evaluate();
        $b1 = (int) DB::table('apkuks')->where('source_id', 'B1')->value('id');
        $this->assertTrue($candidates[$b1]->isEligible());
        $this->assertFalse($candidates->has($a7));
        $this->assertSame($eligibleBefore - 1, app(ApkukCandidateService::class)->summary($candidates)['eligible']);
        $this->assertSame($woBefore, $this->workOrderTables());

        $impact = json_decode(DB::table('apkuk_reconciliations')->value('impact'), true);
        $this->assertSame([$b1], $impact['newly_eligible']);
        $this->assertCount(1, $impact['work_order_refs']);
    }

    public function test_apply_without_audit_table_changes_nothing(): void
    {
        $this->removeReports(['A1']);
        $this->publishSource();
        $plan = $this->planId();
        Schema::drop('apkuk_reconciliations');

        [$code, $output] = $this->artisan2('apkuk:reconcile', $this->approval($plan, 1));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Tabel audit apkuk_reconciliations belum ada', $output);
        $this->assertSame(0, DB::table('apkuks')->whereNotNull('source_missing_at')->count());
    }

    public function test_identity_key_contract_is_unchanged(): void
    {
        // Kunci ini sudah tersimpan di produksi; perubahan formula akan memutus semua identitas.
        $this->assertSame(
            hash('sha256', 'APPSHEET_LAMA|A150|F.REOG.000001 X'),
            ApkukSourceReader::legacyKey('a150', 'f.reog.000001   x'),
        );

        // ID A150 dipakai dua laporan berbeda tiang: tetap dua identitas terpisah, dan dry-run
        // menampilkannya untuk ditinjau tanpa memblokir.
        $stored = DB::table('apkuks')->where('source_id', 'A150')->pluck('legacy_key', 'no_tiang')->all();
        $this->assertCount(2, $stored);
        [$code, $output] = $this->artisan2('apkuk:reconcile');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('A150: F.REOG.000150 (baris 151), F.REOG.900150 (baris 2812)', $output);
        $this->assertMatchesRegularExpression('/RECONCILE_PLAN id=[0-9a-f]{16} missing=0 new=0 returning=0/', $output);
        $this->assertSame(ApkukSourceReader::legacyKey('A150', 'F.REOG.000150'), $stored['F.REOG.000150']);
        $this->assertSame(ApkukSourceReader::legacyKey('A150', 'F.REOG.900150'), $stored['F.REOG.900150']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function approval(string $plan, int $expected): array
    {
        return [
            '--apply' => true,
            '--plan' => $plan,
            '--expect-missing' => (string) $expected,
            '--approved-by' => 'Supervisor Har, ULP Sribawono',
            '--reason' => 'Laporan dihapus di SPS BOS saat pembersihan data; diverifikasi dan disetujui.',
            '--admin' => 'ADMIN_REKON',
        ];
    }

    /**
     * Kata sandi yang "diketik" saat apply meminta kata sandi Admin (null = sesi tidak interaktif).
     */
    private function typePassword(?string $password): void
    {
        $this->app->instance(ConsoleAdminAuthenticator::class, new class($password) extends ConsoleAdminAuthenticator
        {
            public function __construct(private readonly ?string $password) {}

            protected function readPassword(Command $command, string $username): ?string
            {
                return $this->password;
            }
        });
    }

    private function planId(): string
    {
        return $this->planWithParts()[0];
    }

    /**
     * Kode rencana dan awal sidik per komponen dari baris "Sidik persetujuan" dry-run.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function planWithParts(): array
    {
        [, $output] = $this->artisan2('apkuk:reconcile');
        $this->assertMatchesRegularExpression('/RECONCILE_PLAN id=([0-9a-f]{16})/', $output);
        preg_match('/RECONCILE_PLAN id=([0-9a-f]{16})/', $output, $m);
        preg_match('/^Sidik persetujuan: (.+?)\r?$/m', $output, $line);
        preg_match_all('/([^,=]+)=([0-9a-f]{12})/', $line[1], $parts, PREG_SET_ORDER);

        return [$m[1], array_column(array_map(fn (array $p) => [trim($p[1]), $p[2]], $parts), 1, 0)];
    }

    /**
     * Menjalankan $afterFirstPlan tepat setelah validasi awal apply (rencana pertama),
     * meniru penulis lain yang commit sebelum tabel dikunci.
     */
    private function interceptPlanner(Closure $afterFirstPlan): void
    {
        $this->app->instance(ApkukReconciliationPlanner::class, new class($this->app->make(ApkukCandidateService::class), $afterFirstPlan) extends ApkukReconciliationPlanner
        {
            private int $calls = 0;

            public function __construct(ApkukCandidateService $candidates, private readonly Closure $afterFirstPlan)
            {
                parent::__construct($candidates);
            }

            public function plan(ApkukSourceSnapshot $source): ApkukReconciliationPlan
            {
                $plan = parent::plan($source);
                if (++$this->calls === 1) {
                    ($this->afterFirstPlan)();
                }

                return $plan;
            }
        });
    }

    private function createWorkOrderTarget(string $code, WorkOrderStatus $status, int $apkukId): void
    {
        $wo = WorkOrder::create([
            'code' => $code, 'team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL, 'status' => $status, 'work_date' => '2026-10-27',
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
     * @return array{0: int, 1: string}
     */
    private function artisan2(string $command, array $parameters = []): array
    {
        $output = new BufferedOutput;
        $code = Artisan::call($command, $parameters, $output);

        return [$code, $output->fetch()];
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function removeReports(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->reports[$key]);
        }
    }

    private function publishSource(): void
    {
        $this->fakeSheets($this->sourceRows());
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

    /**
     * Sidik isi tabel apkuks (semua kolom, urut id) untuk membuktikan tidak ada perubahan,
     * dibaca lewat cursor agar ribuan baris tidak ditahan di memori.
     */
    private function apkukTable(): string
    {
        $hash = hash_init('sha256');
        foreach (DB::table('apkuks')->orderBy('id')->cursor() as $row) {
            hash_update($hash, json_encode($row));
        }

        return hash_final($hash).'#'.DB::table('apkuks')->count();
    }
}
