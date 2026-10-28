<?php

namespace Tests\Feature\Sync;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Menjalankan railway-sync.sh asli dengan `php` palsu di depan PATH.
 * Tidak memanggil artisan, Google Sheets, maupun database.
 */
class RailwaySyncScriptTest extends TestCase
{
    private string $sh;

    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();

        $sh = (new ExecutableFinder)->find('sh', null, ['C:\\Program Files\\Git\\bin', 'C:\\Program Files\\Git\\usr\\bin']);
        if ($sh === null) {
            $this->markTestSkipped('sh tidak tersedia di mesin ini.');
        }
        $this->sh = $sh;

        $this->binDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'railway-sync-test-'.bin2hex(random_bytes(4));
        mkdir($this->binDir);
        // php palsu: "artisan <command>"; gagal bila command ada di FAKE_FAIL; berhasil dengan
        // antrean rekonsiliasi bila command ada di FAKE_PENDING.
        file_put_contents($this->binDir.DIRECTORY_SEPARATOR.'php', <<<'SH'
#!/bin/sh
echo "fake-php $*"
if [ "$2" != "config:clear" ] && [ ! -s "$GOOGLE_SHEETS_CREDENTIALS_PATH" ]; then
  echo "kredensial tidak tersedia" >&2
  exit 9
fi
case " ${FAKE_FAIL:-} " in
  *" $2 "*) exit "${FAKE_EXIT_CODE:-1}" ;;
esac
case " ${FAKE_PENDING:-} " in
  *" $2 "*) echo "SYNC_RESULT command=$2 status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 duration=0.1s pending=78" ;;
  *) echo "SYNC_RESULT command=$2 status=OK exit=0 duration=0.1s" ;;
esac
exit 0
SH);
        chmod($this->binDir.DIRECTORY_SEPARATOR.'php', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->binDir) && is_dir($this->binDir)) {
            @unlink($this->binDir.DIRECTORY_SEPARATOR.'php');
            @rmdir($this->binDir);
        }

        parent::tearDown();
    }

    public function test_all_commands_succeed_exit_zero_with_ok_summary(): void
    {
        $process = $this->runScript();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(
            ['fake-php artisan gangguan:sync', 'fake-php artisan apkuk:sync', 'fake-php artisan keypoint:sync'],
            $this->syncCalls($process),
        );
        $this->assertStringEndsWith(
            'SYNC_SUMMARY status=OK exit=0 gangguan:sync=OK apkuk:sync=OK keypoint:sync=OK',
            trim($process->getOutput()),
        );
        $this->assertCredentialsRemoved();
    }

    public function test_one_failure_still_runs_remaining_commands_and_exits_one(): void
    {
        $process = $this->runScript(['FAKE_FAIL' => 'apkuk:sync']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertCount(3, $this->syncCalls($process), 'keypoint:sync tetap dijalankan setelah apkuk:sync gagal.');
        $this->assertStringContainsString(
            'SYNC_SUMMARY status=FAILED exit=1 gangguan:sync=OK apkuk:sync=FAILED(exit=1) keypoint:sync=OK',
            $process->getErrorOutput(),
        );
        $this->assertStringNotContainsString('SYNC_SUMMARY', $process->getOutput());
        $this->assertCredentialsRemoved();
    }

    public function test_pending_reconciliation_is_reported_but_not_a_failure(): void
    {
        $process = $this->runScript(['FAKE_PENDING' => 'apkuk:sync']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertCount(3, $this->syncCalls($process), 'keypoint:sync tetap dijalankan.');
        $this->assertStringContainsString('SYNC_RESULT command=apkuk:sync status=SUCCESS_WITH_PENDING_RECONCILIATION', $process->getOutput());
        $this->assertStringEndsWith(
            'SYNC_SUMMARY status=SUCCESS_WITH_PENDING_RECONCILIATION exit=0 gangguan:sync=OK apkuk:sync=SUCCESS_WITH_PENDING_RECONCILIATION keypoint:sync=OK',
            trim($process->getOutput()),
        );
        $this->assertStringNotContainsString('SYNC_SUMMARY', $process->getErrorOutput());
        $this->assertCredentialsRemoved();
    }

    public function test_failure_wins_over_pending_reconciliation(): void
    {
        $process = $this->runScript(['FAKE_PENDING' => 'apkuk:sync', 'FAKE_FAIL' => 'keypoint:sync']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'SYNC_SUMMARY status=FAILED exit=1 gangguan:sync=OK apkuk:sync=SUCCESS_WITH_PENDING_RECONCILIATION keypoint:sync=FAILED(exit=1)',
            $process->getErrorOutput(),
        );
    }

    public function test_summary_keeps_original_exit_codes_of_failed_commands(): void
    {
        $process = $this->runScript(['FAKE_FAIL' => 'gangguan:sync keypoint:sync', 'FAKE_EXIT_CODE' => '255']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'gangguan:sync=FAILED(exit=255) apkuk:sync=OK keypoint:sync=FAILED(exit=255)',
            $process->getErrorOutput(),
        );
    }

    public function test_wrong_database_aborts_before_any_sync(): void
    {
        $process = $this->runScript(['DB_DATABASE' => 'ulp_production_lain']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame([], $this->syncCalls($process));
        $this->assertStringContainsString('reason="Database tidak sesuai. Sinkronisasi dibatalkan."', $process->getErrorOutput());
    }

    public function test_undecodable_credentials_abort_and_leave_no_file(): void
    {
        $process = $this->runScript(['GOOGLE_SHEETS_CREDENTIALS_BASE64' => '%%% bukan base64 %%%']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame([], $this->syncCalls($process));
        $this->assertStringContainsString('reason="Kredensial Google tidak dapat didekode."', $process->getErrorOutput());
        $this->assertCredentialsRemoved();
    }

    public function test_config_clear_failure_aborts_with_reason(): void
    {
        $process = $this->runScript(['FAKE_FAIL' => 'config:clear']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame([], $this->syncCalls($process));
        $this->assertStringContainsString('reason="php artisan config:clear gagal."', $process->getErrorOutput());
    }

    /**
     * @param  array<string, string>  $env
     */
    private function runScript(array $env = []): Process
    {
        $script = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'railway-sync.sh';
        $process = new Process([$this->sh, $script], dirname($script), $env + [
            'PATH' => $this->binDir.PATH_SEPARATOR.getenv('PATH'),
            'GOOGLE_SHEETS_CREDENTIALS_BASE64' => base64_encode('{"type":"service_account"}'),
            'DB_DATABASE' => 'ulp_sribhawono_shared_dev',
            'FAKE_FAIL' => '',
        ]);
        $process->setTimeout(60)->run();

        return $process;
    }

    /**
     * @return array<int, string>
     */
    private function syncCalls(Process $process): array
    {
        return array_values(array_filter(
            preg_split('/\R/', $process->getOutput()) ?: [],
            fn (string $line) => str_starts_with($line, 'fake-php artisan ') && ! str_contains($line, 'config:clear'),
        ));
    }

    private function assertCredentialsRemoved(): void
    {
        $check = new Process([$this->sh, '-c', 'test ! -e /tmp/ulp-google-credentials.json && test ! -e /tmp/ulp-sync-output.txt && test ! -e /tmp/ulp-sync-output.txt.code']);
        $check->run();

        $this->assertSame(0, $check->getExitCode(), 'File kredensial/keluaran sementara masih ada setelah skrip selesai.');
    }
}
