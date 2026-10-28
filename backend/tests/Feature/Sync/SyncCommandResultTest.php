<?php

namespace Tests\Feature\Sync;

use App\Services\GoogleSheetValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Perilaku command sinkronisasi cron Railway: exit code, baris ringkasan
 * SYNC_RESULT, batas jumlah baris log, dan idempoten. Google Sheets dipalsukan;
 * tidak ada jaringan maupun database selain SQLite in-memory.
 */
class SyncCommandResultTest extends TestCase
{
    use RefreshDatabase;

    private const APKUK_RANGE = "'APKUK_RAW'!A1:L";

    private const KEYPOINT_RANGE = "'KEYPOINT_RAW'!A1:E";

    private const GANGGUAN_RANGE = "'GANGGUAN_PUSAT_IMPORT'!A1:CY";

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'sync-cred');
        file_put_contents($this->credentialsPath, '{}');
        config([
            'services.google_sheets.credentials_path' => $this->credentialsPath,
            'services.google_sheets.spreadsheet_id' => 'sheet-test',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    public function test_keypoint_sync_success_reports_ok_and_is_idempotent(): void
    {
        $this->fakeSheets([self::KEYPOINT_RANGE => $this->keypointRows(129)]);

        [$code, $output] = $this->runSync('keypoint:sync');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('SYNC KEYPOINT BERHASIL', $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=keypoint:sync status=OK exit=0 duration=\d+\.\ds\r?$/m', $output);
        $this->assertSame(129, DB::table('keypoints')->count());

        // Rerun dengan sumber sama: tidak ada duplikat, tetap sukses.
        [$code, $output] = $this->runSync('keypoint:sync');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('bertambah: 0', $output);
        $this->assertSame(129, DB::table('keypoints')->count());
        $this->assertSame(129, DB::table('keypoints')->distinct()->count('legacy_key'));
    }

    public function test_keypoint_validation_failure_reports_failed_with_first_error_as_reason(): void
    {
        $rows = $this->keypointRows(129);
        $rows[0][2] = 'NAMA_SALAH';
        $this->fakeSheets([self::KEYPOINT_RANGE => $rows]);

        [$code, $output] = $this->runSync('keypoint:sync');

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression(
            '/^SYNC_RESULT command=keypoint:sync status=FAILED exit=1 duration=\d+\.\ds reason="Header kolom 3 salah: \'NAMA_SALAH\', seharusnya \'NAMA\'\."\r?$/m',
            $output,
        );
        $this->assertSame(0, DB::table('keypoints')->count());
    }

    public function test_apkuk_mass_missing_identities_are_rejected_with_bounded_log_and_no_writes(): void
    {
        $this->seedApkukIdentitiesAbsentFromSource(700);
        $before = DB::table('apkuks')->get()->map(fn ($row) => (array) $row)->all();
        $this->fakeSheets([self::APKUK_RANGE => $this->apkukRows(2800)]);

        [$code, $output] = $this->runSync('apkuk:sync');
        $lines = substr_count(rtrim($output), "\n") + 1;

        $this->assertSame(1, $code);
        // Daftar identitas dibatasi; 700 baris per identitas dulu melampaui batas log Railway.
        $this->assertLessThan(80, $lines, "Output apkuk:sync {$lines} baris.");
        $this->assertStringContainsString('700 identitas aktif hilang dari sumber sekaligus', $output);
        $this->assertStringContainsString('… dan 680 lainnya', $output);
        $this->assertStringContainsString('Snapshot ditolak pengaman; tidak ada data APKUK yang ditulis.', $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=FAILED exit=1 .*stage=SNAPSHOT_GUARD .*reason="700 identitas aktif hilang dari sumber sekaligus, melebihi batas 35 per eksekusi\./m', $output);
        // Aturan perlindungan data tetap: tidak ada baris yang ditulis atau diubah, antrean kosong.
        $this->assertSame($before, DB::table('apkuks')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, DB::table('apkuk_reconciliation_items')->count());
        $this->assertSame('FAILED', DB::table('apkuk_sync_runs')->value('status'));
    }

    public function test_apkuk_sync_success_is_idempotent(): void
    {
        $this->fakeSheets([self::APKUK_RANGE => $this->apkukRows(2800)]);

        [$code, $output] = $this->runSync('apkuk:sync');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('SYNC APKUK BERHASIL', $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=SUCCESS exit=0 .* new=2800 updated=0 returned=0 pending=0 /m', $output);
        $this->assertSame(2800, DB::table('apkuks')->count());

        [$code, $output] = $this->runSync('apkuk:sync');
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('bertambah: 0; diperbarui: 0.', $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=apkuk:sync status=SUCCESS exit=0 .* new=0 updated=0 /m', $output);
        $this->assertSame(2800, DB::table('apkuks')->count());
        $this->assertSame(2800, DB::table('apkuks')->distinct()->count('legacy_key'));
    }

    public function test_apkuk_validation_issue_list_is_bounded_and_counted(): void
    {
        $rows = $this->apkukRows(2800);
        foreach (range(1, 150) as $i) {
            $rows[$i][5] = ''; // No. tiang kosong
        }
        $this->fakeSheets([self::APKUK_RANGE => $rows]);

        [$code, $output] = $this->runSync('apkuk:sync');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('… dan 130 lainnya', $output);
        $this->assertLessThan(60, substr_count($output, "\n"));
        $this->assertSame(0, DB::table('apkuks')->count());
    }

    public function test_gangguan_sync_success_and_rerun_without_duplicates(): void
    {
        $this->fakeSheets([self::GANGGUAN_RANGE => $this->gangguanRows(['APKT-1', 'APKT-2', 'APKT-3'])]);

        [$code, $output] = $this->runSync('gangguan:sync');
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=gangguan:sync status=OK exit=0 /m', $output);
        $this->assertSame(3, DB::table('gangguans')->count());

        [$code] = $this->runSync('gangguan:sync');
        $this->assertSame(0, $code);
        $this->assertSame(3, DB::table('gangguans')->count());
    }

    public function test_google_read_failure_reports_failed_with_reason(): void
    {
        $this->fakeSheets([self::GANGGUAN_RANGE => new RuntimeException('quota exceeded')]);

        [$code, $output] = $this->runSync('gangguan:sync');

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=gangguan:sync status=FAILED exit=1 .*reason="Gagal membaca Google Sheets: quota exceeded"\r?$/m', $output);
    }

    public function test_every_sync_command_fails_cleanly_without_credentials(): void
    {
        config(['services.google_sheets.credentials_path' => $this->credentialsPath.'-tidak-ada']);

        foreach (['gangguan:sync', 'apkuk:sync', 'keypoint:sync'] as $command) {
            [$code, $output] = $this->runSync($command);
            $this->assertSame(1, $code, $command);
            $this->assertMatchesRegularExpression("/^SYNC_RESULT command={$command} status=FAILED exit=1 .*reason=\"/m", $output);
        }
    }

    public function test_unexpected_exception_still_writes_failed_result_before_propagating(): void
    {
        // Baris rusak (bukan array) memicu TypeError di luar blok try command.
        $this->fakeSheets([self::KEYPOINT_RANGE => [$this->keypointRows(1)[0], 'baris-rusak']]);
        $output = new BufferedOutput;

        try {
            Artisan::call('keypoint:sync', [], $output);
            $this->fail('Exception tidak diteruskan.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(\TypeError::class, $e);
        }

        $this->assertMatchesRegularExpression('/^SYNC_RESULT command=keypoint:sync status=FAILED exit=1 .*reason="TypeError: /m', $output->fetch());
    }

    /**
     * @param  array<string, array<int, mixed>|Throwable>  $byRange
     */
    private function fakeSheets(array $byRange): void
    {
        $this->app->instance(GoogleSheetValues::class, new class($byRange) extends GoogleSheetValues
        {
            public function __construct(private array $byRange) {}

            public function get(string $credentialsPath, string $spreadsheetId, string $range, array $params = []): array
            {
                $value = $this->byRange[$range] ?? [];
                if ($value instanceof Throwable) {
                    throw $value;
                }

                return $value;
            }
        });
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runSync(string $command): array
    {
        $output = new BufferedOutput;
        $code = Artisan::call($command, [], $output);

        return [$code, $output->fetch()];
    }

    private function keypointRows(int $count): array
    {
        $rows = [['PENYULANG', 'JENIS', 'NAMA', 'KOORDINAT', 'NOTE']];
        foreach (range(1, $count) as $i) {
            $rows[] = ['REOG', 'FCO', "FCO TEST {$i}", '-5.2,105.7', null];
        }

        return $rows;
    }

    private function apkukRows(int $count): array
    {
        $rows = [[
            'ID', 'Tanggal', 'Petugas', 'Penyulang', 'Section',
            'No. Tiang/Gardu', 'Naik Dari', 'Terpasang bh',
            'Kurang bh', 'Status', 'Koordinat', 'Foto',
        ]];
        foreach (range(1, $count) as $i) {
            $rows[] = ["A{$i}", 45950, 'Petugas', 'REOG', 'SEC 1', sprintf('F.REOG.%06d', $i), 'TR', 1, 2, 'BELUM EKSEKUSI', '-5.2,105.7', null];
        }

        return $rows;
    }

    private function seedApkukIdentitiesAbsentFromSource(int $count): void
    {
        foreach (array_chunk(range(1, $count), 200) as $chunk) {
            DB::table('apkuks')->insert(array_map(fn (int $i) => [
                'source_id' => "LAMA{$i}",
                'legacy_key' => hash('sha256', "lama|{$i}"),
                'no_tiang' => sprintf('F.LAMA.%06d', $i),
                'sumber_input' => 'APPSHEET_LAMA',
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function gangguanRows(array $ids): array
    {
        $header = array_fill(0, 103, '');
        foreach ([
            0 => 'Penyulang', 2 => 'ULP Koreksi', 3 => 'Tanggal',
            25 => 'No: Transaksi APKT', 51 => 'NoTi',
            79 => 'FOTO CORRECTIVE', 80 => 'FOTO GANGGUAN',
            91 => 'HEWAN', 96 => 'ENS APKT', 98 => 'KEYPOINT TERGANGGU',
        ] as $index => $name) {
            $header[$index] = $name;
        }

        $rows = [$header];
        foreach ($ids as $id) {
            $row = array_fill(0, 103, '');
            $row[0] = 'REOG';
            $row[1] = 'REC TEST';
            $row[2] = 'SRIBAWONO';
            $row[3] = 45950;
            $row[25] = $id;
            $rows[] = $row;
        }

        return $rows;
    }
}
