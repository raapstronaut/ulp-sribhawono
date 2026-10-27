<?php

namespace App\Console\Commands\Concerns;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Keluaran ringkas untuk command sinkronisasi yang dijalankan cron Railway.
 *
 * - Daftar per baris dibatasi (Railway membuang log di atas 500 baris/detik,
 *   sehingga pesan penyebab gagal bisa ikut hilang).
 * - Setiap eksekusi diakhiri satu baris SYNC_RESULT berisi status, exit code,
 *   durasi, dan alasan (pesan error pertama). Baris gagal ditulis ke stderr.
 */
trait ReportsSyncResult
{
    private ?string $firstSyncError = null;

    public function error($string, $verbosity = null)
    {
        $this->firstSyncError ??= (string) $string;

        parent::error($string, $verbosity);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->firstSyncError = null;
        $started = microtime(true);

        try {
            $code = parent::execute($input, $output);
        } catch (Throwable $e) {
            $this->writeSyncResult(self::FAILURE, $started, $e::class.': '.$e->getMessage());

            throw $e;
        }

        $this->writeSyncResult($code, $started, $this->firstSyncError);

        return $code;
    }

    /**
     * @param  iterable<int, string>  $items
     */
    protected function listLimited(iterable $items, int $limit = 20): void
    {
        $shown = 0;
        $total = 0;
        foreach ($items as $item) {
            $total++;
            if ($shown < $limit) {
                $this->line(' - '.$item);
                $shown++;
            }
        }

        if ($total > $shown) {
            $this->line(' … dan '.($total - $shown).' lainnya (tidak ditampilkan agar log tidak dibuang Railway)');
        }
    }

    private function writeSyncResult(int $code, float $started, ?string $reason): void
    {
        $line = sprintf(
            'SYNC_RESULT command=%s status=%s exit=%d duration=%.1fs',
            $this->getName(),
            $code === self::SUCCESS ? 'OK' : 'FAILED',
            $code,
            microtime(true) - $started,
        );

        if ($code !== self::SUCCESS) {
            $reason = preg_replace('/\s+/u', ' ', trim($reason ?? 'tanpa pesan error'));
            $line .= ' reason="'.str_replace('"', "'", mb_strimwidth($reason, 0, 300, '…')).'"';
        }

        $output = $this->output->getOutput();
        if ($code !== self::SUCCESS && $output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }

        $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
