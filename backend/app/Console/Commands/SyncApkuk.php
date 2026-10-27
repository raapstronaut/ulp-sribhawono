<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsSyncResult;
use App\Services\Apkuk\ApkukSourceException;
use App\Services\Apkuk\ApkukSourceReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApkuk extends Command
{
    use ReportsSyncResult;

    protected $signature = 'apkuk:sync {--dry-run : Validasi saja, tanpa menyimpan ke PostgreSQL}';

    protected $description = 'Sinkronisasi APKUK_RAW dari MASTER_DATA ke tabel apkuks';

    public function handle(ApkukSourceReader $reader): int
    {
        // Baca, validasi, dan hitung identitas di ApkukSourceReader (dipakai juga oleh apkuk:reconcile).
        try {
            $source = $reader->read();
        } catch (ApkukSourceException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $records = $source->records;
        $keyToSheetRow = $source->keyToSheetRow;
        $issues = $source->issues;
        $negativeCounts = $source->negativeCounts;
        $reportCount = $source->reportCount;
        $totalIds = count($source->sourceIds);
        $duplicatedSourceIds = $source->duplicatedSourceIds();

        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Baris APKUK terisi', $reportCount],
            ['Siap diproses', count($records)],
            ['ID AppSheet berbeda', $totalIds],
            ['ID AppSheet dipakai >1 kali', count($duplicatedSourceIds)],
            ['Kombinasi ID + tiang berbeda', count($keyToSheetRow)],
            ['Kesalahan validasi', count($issues)],
            ['Jumlah negatif (tetap disimpan)', count($negativeCounts)],
        ]);

        if ($duplicatedSourceIds !== []) {
            $ids = array_keys($duplicatedSourceIds);
            $extra = count($ids) > 20 ? ' … dan ' . (count($ids) - 20) . ' lainnya' : '';
            $this->warn('ID AppSheet yang digunakan pada beberapa laporan: ' . implode(', ', array_slice($ids, 0, 20)) . $extra);
        }
        if ($negativeCounts !== []) {
            $this->warn('Ada nilai jumlah negatif di sumber. Nilai aslinya dipertahankan:');
            $this->listLimited($negativeCounts, 10);
        }
        if ($issues !== []) {
            $this->error('Ada data tidak valid; seluruh proses dibatalkan supaya tidak ada laporan terlewat atau ditimpa:');
            $this->listLimited($issues);
            return self::FAILURE;
        }
        if ($reportCount < ApkukSourceReader::MIN_EXPECTED_ROWS || count($records) !== $reportCount) {
            $this->error('Jumlah data sumber turun/berbeda dari hasil validasi. PostgreSQL tidak diubah.');
            return self::FAILURE;
        }

        try {
            // Data AppSheet lama tidak boleh menimpa data input aplikasi mobile.
            $existing = DB::table('apkuks')
                ->where('sumber_input', ApkukSourceReader::SOURCE)
                ->whereNotNull('legacy_key')
                ->whereNull('source_missing_at')
                ->get(['legacy_key', 'source_id', 'no_tiang']);

            $incoming = array_fill_keys(array_keys($keyToSheetRow), true);

            $missing = $existing->filter(
                fn ($old) => !isset($incoming[$old->legacy_key])
            );

            if ($missing->isNotEmpty()) {
                $this->error($missing->count() . ' identitas lama di PostgreSQL tidak ditemukan di sumber terbaru.');

                // Dulu dicetak satu baris per identitas tanpa batas: ratusan baris sekaligus
                // melampaui batas log Railway sehingga pesan penyebab gagal ikut dibuang.
                $this->listLimited($missing->map(
                    fn ($old) => 'ID: ' . ($old->source_id ?? '(kosong)') . ' | Tiang: ' . ($old->no_tiang ?? '(kosong)')
                ));

                $this->error('Sync dibatalkan demi mencegah duplikat.');
                // Penandaan source_missing_at hanya lewat rencana rekonsiliasi yang disetujui.
                $this->line('Tinjau selisihnya dengan `php artisan apkuk:reconcile` (dry-run, tidak menulis database).');
                return self::FAILURE;
            }
            if ($this->option('dry-run')) {
                $this->info('DRY RUN BERHASIL. Data PostgreSQL tidak diubah.');
                return self::SUCCESS;
            }

            // Laporan yang pernah ditandai hilang lalu muncul kembali dipulihkan oleh upsert
            // (source_missing_at => null); jumlahnya dilaporkan agar tercatat di log.
            $restored = DB::table('apkuks')
                ->where('sumber_input', ApkukSourceReader::SOURCE)
                ->whereNotNull('source_missing_at')
                ->pluck('legacy_key')
                ->filter(fn ($key) => isset($incoming[$key]))
                ->count();

            $before = DB::table('apkuks')->where('sumber_input', ApkukSourceReader::SOURCE)->count();
            $toUpdate = array_values(array_diff(array_keys($records[0]), [
                'legacy_key', 'created_at', 'sumber_input',
            ]));

            DB::transaction(function () use ($records, $toUpdate): void {
                foreach (array_chunk($records, 200) as $chunk) {
                    DB::table('apkuks')->upsert($chunk, ['legacy_key'], $toUpdate);
                }
            });

            $after = DB::table('apkuks')->where('sumber_input', ApkukSourceReader::SOURCE)->count();
            $this->info("SYNC APKUK BERHASIL. Total data AppSheet lama: {$after}; bertambah: " . ($after - $before) . '.');
            if ($restored > 0) {
                $this->info("Laporan yang muncul kembali di sumber dan dipulihkan dari source_missing_at: {$restored}.");
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sinkronisasi gagal; transaksi database dibatalkan: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
