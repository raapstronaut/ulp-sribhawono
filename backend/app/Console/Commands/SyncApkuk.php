<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsSyncResult;
use App\Models\ApkukSyncRun;
use App\Services\Apkuk\ApkukSnapshotRejected;
use App\Services\Apkuk\ApkukSourceException;
use App\Services\Apkuk\ApkukSourceReader;
use App\Services\Apkuk\ApkukSourceSnapshot;
use App\Services\Apkuk\ApkukSynchronizer;
use App\Services\Apkuk\ApkukSyncLock;
use App\Services\Apkuk\ApkukSyncPlan;
use App\Services\Apkuk\ApkukSyncPlanner;
use App\Services\Apkuk\ApkukSyncPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sinkronisasi APKUK_RAW → apkuks yang tahan perubahan normal di sumber.
 *
 * Status akhir (baris SYNC_RESULT dan apkuk_sync_runs):
 * - SUCCESS: snapshot diterapkan, tidak ada antrean rekonsiliasi.
 * - SUCCESS_WITH_PENDING_RECONCILIATION (exit 0): snapshot diterapkan; laporan yang hilang
 *   atau berubah identitas menunggu keputusan Admin, tidak dihapus dan tidak ditandai.
 * - FAILED (exit 1): sumber gagal dibaca/tidak valid, snapshot mencurigakan (terpotong,
 *   hilang/bertambah/berubah massal), atau database gagal. Tidak ada data APKUK yang ditulis.
 */
class SyncApkuk extends Command
{
    use ReportsSyncResult;

    protected $signature = 'apkuk:sync
        {--dry-run : Validasi dan tampilkan rencana saja, tanpa menyimpan ke PostgreSQL}
        {--max-missing= : Eksekusi manual: batas identitas yang baru hilang (hanya masuk antrean, tidak dihapus)}
        {--max-new= : Eksekusi manual: batas laporan baru/muncul kembali}
        {--max-updated= : Eksekusi manual: batas laporan yang isinya berubah}';

    protected $description = 'Sinkronisasi APKUK_RAW dari MASTER_DATA ke apkuks; laporan yang hilang masuk antrean rekonsiliasi';

    public function handle(ApkukSourceReader $reader, ApkukSyncPlanner $planner, ApkukSynchronizer $synchronizer): int
    {
        $overrides = $this->overrides();
        if ($overrides === null) {
            return self::FAILURE;
        }
        if (! Schema::hasTable('apkuk_sync_runs') || ! Schema::hasTable('apkuk_reconciliation_items')) {
            $this->error('Tabel apkuk_sync_runs/apkuk_reconciliation_items belum ada. Jalankan migration additive 2026_10_28_100001 terlebih dahulu. PostgreSQL tidak diubah.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $run = null;
        if (! $dryRun) {
            try {
                $run = ApkukSyncRun::create([
                    'status' => ApkukSyncRun::STATUS_RUNNING,
                    'started_at' => now(),
                    'guard' => ['overrides' => array_filter($overrides, fn (?int $v) => $v !== null)],
                ]);
            } catch (Throwable $e) {
                $this->error('Database tidak dapat dicatat; sinkronisasi dibatalkan: '.Str::limit($e->getMessage(), 300));

                return self::FAILURE;
            }
        }

        try {
            $source = $reader->read();
        } catch (ApkukSourceException $e) {
            $this->error($e->getMessage());

            return $this->recordFailure($run, ApkukSyncRun::STAGE_SOURCE_READ, $e->getMessage());
        }

        $this->reportSource($source);

        if ($source->issues !== []) {
            $this->error('Ada data tidak valid; seluruh proses dibatalkan supaya tidak ada laporan terlewat atau ditimpa:');
            $this->listLimited($source->issues);

            return $this->recordFailure($run, ApkukSyncRun::STAGE_SOURCE_VALIDATION, count($source->issues).' kesalahan validasi; pertama: '.$source->issues[0]);
        }
        if ($source->reportCount < ApkukSourceReader::MIN_EXPECTED_ROWS || count($source->records) !== $source->reportCount) {
            $message = "Jumlah laporan sumber ({$source->reportCount}) kurang dari ".ApkukSourceReader::MIN_EXPECTED_ROWS.' atau berbeda dari hasil validasi; kemungkinan Google Sheets terpotong/belum lengkap. PostgreSQL tidak diubah.';
            $this->error($message);

            return $this->recordFailure($run, ApkukSyncRun::STAGE_SOURCE_VALIDATION, $message);
        }

        $policy = ApkukSyncPolicy::fromConfig($overrides['max_missing'], $overrides['max_new'], $overrides['max_updated']);

        if ($dryRun) {
            return $this->dryRun($source, $planner, $policy);
        }

        try {
            $outcome = $synchronizer->apply($source, $policy, $run);
        } catch (ApkukSnapshotRejected $e) {
            $this->reportPlan($e->plan, $e->limits);
            foreach ($e->violations as $violation) {
                $this->error($violation);
            }
            $this->error('Snapshot ditolak pengaman; tidak ada data APKUK yang ditulis. Periksa APKUK_RAW. Bila perubahan besar ini memang benar, tinjau `php artisan apkuk:sync --dry-run` lalu jalankan manual dengan --max-missing/--max-new/--max-updated.');

            return $this->recordFailure($run, ApkukSyncRun::STAGE_SNAPSHOT_GUARD, $e->violations[0], $e->plan->counts(), $e->limits + ['violations' => $e->violations]);
        } catch (Throwable $e) {
            // 55P03 = lock_not_available: penulis APKUK lain (sync/rekonsiliasi/keputusan Admin) masih berjalan.
            $message = $e instanceof QueryException && $e->getCode() === '55P03'
                ? 'Kunci data APKUK dipegang proses lain (apkuk:sync, apkuk:reconcile, atau keputusan Admin) lebih dari '.ApkukSyncLock::LOCK_TIMEOUT.'; transaksi dibatalkan, tidak ada data APKUK yang ditulis. Eksekusi berikutnya mencoba lagi.'
                : 'Sinkronisasi gagal; transaksi database dibatalkan, tidak ada data APKUK yang ditulis: '.Str::limit($e->getMessage(), 500);
            $this->error($message);

            return $this->recordFailure($run, ApkukSyncRun::STAGE_DATABASE, $message);
        }

        $plan = $outcome->plan;
        $counts = $plan->counts();
        $this->reportPlan($plan, $outcome->limits);

        $total = DB::table('apkuks')->where('sumber_input', ApkukSourceReader::SOURCE)->count();
        $this->info("SYNC APKUK BERHASIL. Total data AppSheet lama: {$total}; bertambah: {$counts['inserted']}; diperbarui: {$counts['updated']}.");
        if ($counts['returned'] > 0) {
            $this->info("Laporan yang muncul kembali di sumber dan dipulihkan dari source_missing_at: {$counts['returned']}.");
        }
        if ($counts['pending_resolved'] > 0) {
            $this->info("Antrean rekonsiliasi yang selesai otomatis (muncul kembali/digantikan): {$counts['pending_resolved']}.");
        }
        if ($counts['pending_open'] > 0) {
            $this->warn(sprintf(
                'PERINGATAN: %d laporan menunggu rekonsiliasi (%d baru pada eksekusi ini). Laporan tidak dihapus dan tidak dapat dipilih untuk WO baru sampai diputuskan Admin (Web Admin /admin/apkuk-rekonsiliasi) atau muncul kembali di sumber. Daftar: php artisan apkuk:pending',
                $counts['pending_open'],
                $counts['pending_new'],
            ));
        }

        $this->setSyncResult($outcome->status, [
            'run' => $run->id,
            'new' => $counts['inserted'],
            'updated' => $counts['updated'],
            'returned' => $counts['returned'],
            'pending' => $counts['pending_open'],
            'pending_new' => $counts['pending_new'],
            'pending_resolved' => $counts['pending_resolved'],
            'identity_changes' => $counts['identity_changes'],
        ]);

        return self::SUCCESS;
    }

    private function dryRun(ApkukSourceSnapshot $source, ApkukSyncPlanner $planner, ApkukSyncPolicy $policy): int
    {
        $plan = $planner->planReadOnly($source);
        $baseline = ApkukSyncPolicy::baseline($plan);
        $limits = $policy->limits($plan, $baseline);
        $this->reportPlan($plan, $limits);

        $violations = $policy->violations($plan, $baseline);
        foreach ($violations as $violation) {
            $this->error($violation);
        }
        if ($violations !== []) {
            $this->line('DRY RUN: snapshot ini akan DITOLAK pengaman. Data PostgreSQL tidak diubah.');

            return self::FAILURE;
        }

        $this->info('DRY RUN BERHASIL. Data PostgreSQL tidak diubah.');
        $this->setSyncResult('DRY_RUN', ['new' => count($plan->inserts), 'updated' => count($plan->updates), 'pending' => count($plan->desiredItems)]);

        return self::SUCCESS;
    }

    /**
     * @return array{max_missing: ?int, max_new: ?int, max_updated: ?int}|null
     */
    private function overrides(): ?array
    {
        $values = [];
        foreach (['max_missing' => 'max-missing', 'max_new' => 'max-new', 'max_updated' => 'max-updated'] as $key => $option) {
            $raw = $this->option($option);
            if ($raw === null || $raw === '') {
                $values[$key] = null;

                continue;
            }
            if (! ctype_digit((string) $raw)) {
                $this->error("Opsi --{$option} harus bilangan bulat ≥ 0. PostgreSQL tidak diubah.");

                return null;
            }
            $values[$key] = (int) $raw;
        }

        return $values;
    }

    /**
     * @param  array<string, int>|null  $counts
     * @param  array<string, mixed>|null  $guard
     */
    private function recordFailure(?ApkukSyncRun $run, string $stage, string $reason, ?array $counts = null, ?array $guard = null): int
    {
        $this->setSyncResult(null, array_filter(['stage' => $stage, 'run' => $run?->id]));

        if ($run !== null) {
            try {
                $run->forceFill([
                    'status' => ApkukSyncRun::STATUS_FAILED,
                    'failure_stage' => $stage,
                    'failure_reason' => Str::limit($reason, 2000),
                    'counts' => $counts,
                    'guard' => $guard ?? $run->guard,
                    'finished_at' => now(),
                ])->save();
            } catch (Throwable $e) {
                Log::error('apkuk:sync tidak dapat mencatat status FAILED', ['run' => $run->id, 'error' => $e->getMessage()]);
            }
        }

        return self::FAILURE;
    }

    private function reportSource(ApkukSourceSnapshot $source): void
    {
        $duplicatedSourceIds = $source->duplicatedSourceIds();
        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Baris APKUK terisi', $source->reportCount],
            ['Siap diproses', count($source->records)],
            ['ID AppSheet berbeda', count($source->sourceIds)],
            ['ID AppSheet dipakai >1 kali', count($duplicatedSourceIds)],
            ['Kombinasi ID + tiang berbeda', count($source->keyToSheetRow)],
            ['Kesalahan validasi', count($source->issues)],
            ['Jumlah negatif (tetap disimpan)', count($source->negativeCounts)],
        ]);

        if ($duplicatedSourceIds !== []) {
            $ids = array_keys($duplicatedSourceIds);
            $extra = count($ids) > 20 ? ' … dan '.(count($ids) - 20).' lainnya' : '';
            $this->warn('ID AppSheet yang digunakan pada beberapa laporan: '.implode(', ', array_slice($ids, 0, 20)).$extra);
        }
        if ($source->negativeCounts !== []) {
            $this->warn('Ada nilai jumlah negatif di sumber. Nilai aslinya dipertahankan:');
            $this->listLimited($source->negativeCounts, 10);
        }
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function reportPlan(ApkukSyncPlan $plan, array $limits): void
    {
        $counts = $plan->counts();
        $this->table(['Perubahan terhadap PostgreSQL', 'Jumlah'], [
            ['Laporan baru (disisipkan)', $counts['inserted']],
            ['Diperbarui (isi berubah)', $counts['updated']],
            ['Pindah baris sheet (isi sama)', $counts['moved']],
            ['Muncul kembali (dipulihkan dari histori)', $counts['returned']],
            ['Tidak berubah', $counts['unchanged']],
            ['Hilang dari sumber (antrean, tidak dihapus)', $counts['missing']],
            ['  di antaranya baru hilang pada eksekusi ini', count($plan->newlyMissingIds)],
            ['Dugaan perubahan identitas (ditahan, tidak disisipkan)', $counts['identity_changes']],
            ['Antrean terbuka setelah eksekusi', $counts['pending_open']],
            ['Antrean selesai otomatis', $counts['pending_resolved']],
        ]);
        $this->line(sprintf(
            'Pengaman: pembanding %s laporan; batas turun %s, baru hilang %d, baru/muncul kembali %s, berubah isi %d%s.',
            $limits['baseline'] ?? '-',
            $limits['max_drop'] ?? '-',
            $limits['max_new_missing'],
            $limits['max_new'] ?? 'tanpa batas',
            $limits['max_updated'],
            $limits['overrides'] === [] ? '' : ' (opsi manual: '.http_build_query($limits['overrides'], '', ', ').')',
        ));

        $newly = array_flip($plan->newlyMissingIds);
        $newlyMissing = array_filter($plan->missing, fn (array $row) => isset($newly[$row['apkuk_id']]));
        if ($newlyMissing !== []) {
            $this->warn('Identitas yang baru hilang dari sumber (masuk antrean, tidak dihapus):');
            $this->listLimited(array_map(fn (array $row) => sprintf(
                'apkuk #%d | ID %s | Tiang %s | %s | %s | %s',
                $row['apkuk_id'], $row['source_id'] ?? '(kosong)', $row['no_tiang'] ?? '(kosong)',
                $row['tanggal'] ?? '-', $row['penyulang'] ?? '-', $row['status'] ?? '-',
            ), $newlyMissing));
        }
        if ($plan->held !== []) {
            $this->warn('Identitas baru yang ditahan (ID AppSheet sama dengan laporan yang hilang; perlu keputusan Admin):');
            $this->listLimited(array_map(fn (array $entry) => sprintf(
                'ID %s | Tiang baru %s | baris %d | laporan lama: %s',
                $entry['record']['source_id'], $entry['record']['no_tiang'], $entry['record']['source_row_number'],
                implode(', ', array_map(fn (int $id) => '#'.$id, $entry['candidate_apkuk_ids'])),
            ), $plan->held));
        }
    }
}
