<?php

namespace App\Console\Commands;

use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncKeypoint extends Command
{
    protected $signature = 'keypoint:sync {--dry-run : Periksa sumber dan identitas, tanpa menyimpan ke PostgreSQL}';

    protected $description = 'Sinkronisasi KEYPOINT_RAW MASTER_DATA ke tabel keypoints';

    // Hasil audit awal: 129 baris data. Jika IMPORTRANGE belum lengkap, batalkan.
    private const MIN_EXPECTED_ROWS = 120;

    private const SOURCE = 'SPS_BOS';

    public function handle(): int
    {
        $credentialsPath = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (!$credentialsPath || !is_file($credentialsPath) || !$spreadsheetId) {
            $this->error('Kredensial atau Spreadsheet ID Google Sheets belum lengkap di config/services.php.');
            return self::FAILURE;
        }

        try {
            $client = new Client();
            $client->setAuthConfig($credentialsPath);
            $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
            $service = new Sheets($client);
            $result = $service->spreadsheets_values->get(
                $spreadsheetId,
                "'KEYPOINT_RAW'!A1:E",
                ['valueRenderOption' => 'UNFORMATTED_VALUE']
            );
            $rows = $result->getValues() ?? [];
        } catch (\Throwable $e) {
            $this->error('Google Sheets gagal dibaca: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (count($rows) < 2) {
            $this->error('KEYPOINT_RAW kosong/belum selesai dimuat. PostgreSQL tidak diubah.');
            return self::FAILURE;
        }

        $expectedHeaders = ['PENYULANG', 'JENIS', 'NAMA', 'KOORDINAT', 'NOTE'];
        foreach ($expectedHeaders as $index => $expected) {
            $actual = trim((string) ($rows[0][$index] ?? ''));
            if ($actual !== $expected) {
                $this->error('Header kolom ' . ($index + 1) . " salah: '{$actual}', seharusnya '{$expected}'.");
                return self::FAILURE;
            }
        }

        $records = [];
        $keysAndRows = [];
        $issues = [];
        $nonEmptyRows = 0;
        $now = now();

        foreach (array_slice($rows, 1) as $index => $row) {
            $sheetRow = $index + 2;
            $row = array_pad($row, 5, null);

            if (count(array_filter($row, fn ($value) => trim((string) ($value ?? '')) !== '')) === 0) {
                continue;
            }
            $nonEmptyRows++;

            $penyulang = $this->text($row[0]);
            $jenis = $this->text($row[1]);
            $nama = $this->text($row[2]);
            $koordinatRaw = $this->text($row[3]);
            $note = $this->text($row[4]);

            if ($penyulang === null || $jenis === null || $nama === null) {
                $issues[] = "Baris {$sheetRow}: PENYULANG, JENIS, atau NAMA kosong";
                continue;
            }

            if (mb_strlen($penyulang) > 255 || mb_strlen($jenis) > 255 || mb_strlen($nama) > 255) {
                $issues[] = "Baris {$sheetRow}: identitas Keypoint melebihi 255 karakter";
                continue;
            }

            // Pada audit awal 129 data, kombinasi penyulang + jenis + nama unik.
            // Koordinat dan NOTE sengaja tidak menjadi bagian identitas agar pembaruannya tidak menduplikasi data.
            $key = hash('sha256', self::SOURCE . '|'
                . $this->normalizeIdentity($penyulang) . '|'
                . $this->normalizeIdentity($jenis) . '|'
                . $this->normalizeIdentity($nama));

            if (isset($keysAndRows[$key])) {
                $issues[] = "Baris {$keysAndRows[$key]} dan {$sheetRow}: kombinasi PENYULANG + JENIS + NAMA sama";
                continue;
            }
            $keysAndRows[$key] = $sheetRow;

            [$latitude, $longitude] = $this->parseCoordinates($koordinatRaw);
            if ($latitude === null || $longitude === null) {
                $issues[] = "Baris {$sheetRow}: koordinat kosong/tidak valid (" . ($koordinatRaw ?? 'KOSONG') . ')';
                continue;
            }

            $records[] = [
                'legacy_key' => $key,
                'penyulang' => $penyulang,
                'jenis' => $jenis,
                'nama' => $nama,
                'koordinat_raw' => $koordinatRaw,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'note' => $note,
                'sumber_input' => self::SOURCE,
                'source_row_number' => $sheetRow,
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Baris Keypoint terisi', $nonEmptyRows],
            ['Siap diproses', count($records)],
            ['Identitas unik', count($keysAndRows)],
            ['Kesalahan validasi', count($issues)],
        ]);

        if ($issues !== []) {
            $this->error('Ada data yang perlu diperbaiki. PostgreSQL tidak diubah:');
            foreach (array_slice($issues, 0, 20) as $issue) {
                $this->line(' - ' . $issue);
            }
            return self::FAILURE;
        }

        if ($nonEmptyRows < self::MIN_EXPECTED_ROWS || count($records) !== $nonEmptyRows) {
            $this->error('Jumlah data sumber terlalu sedikit/tidak sama dengan data valid. Sync dibatalkan.');
            return self::FAILURE;
        }

        try {
            $existing = DB::table('keypoints')
                ->where('sumber_input', self::SOURCE)
                ->whereNotNull('legacy_key')
                ->pluck('legacy_key')
                ->all();

            $missing = array_values(array_filter(
                $existing,
                fn ($key) => !isset($keysAndRows[$key])
            ));

            if ($missing !== []) {
                $this->error(count($missing) . ' identitas Keypoint lama tidak ditemukan pada sumber terbaru.');
                $this->error('Kemungkinan PENYULANG/JENIS/NAMA berubah, data dihapus, atau IMPORTRANGE belum lengkap.');
                $this->error('Sinkronisasi dibatalkan agar tidak membuat duplikat. Periksa dan rekonsiliasi dahulu.');
                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info('DRY RUN KEYPOINT BERHASIL. PostgreSQL tidak diubah.');
                return self::SUCCESS;
            }

            $before = DB::table('keypoints')->where('sumber_input', self::SOURCE)->count();
            $updateColumns = array_values(array_diff(array_keys($records[0]), [
                'legacy_key', 'sumber_input', 'created_at',
            ]));

            DB::transaction(function () use ($records, $updateColumns): void {
                foreach (array_chunk($records, 200) as $chunk) {
                    DB::table('keypoints')->upsert($chunk, ['legacy_key'], $updateColumns);
                }
            });

            $after = DB::table('keypoints')->where('sumber_input', self::SOURCE)->count();
            $this->info("SYNC KEYPOINT BERHASIL. Total data SPS bos: {$after}; bertambah: " . ($after - $before) . '.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sync Keypoint gagal; transaksi database dibatalkan: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function normalizeIdentity(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    /** @return array{?string, ?string} */
    private function parseCoordinates(?string $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }
        if (!preg_match('/^\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*$/', $raw, $matches)) {
            return [null, null];
        }

        $lat = (float) $matches[1];
        $lon = (float) $matches[2];
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return [null, null];
        }

        return [number_format($lat, 7, '.', ''), number_format($lon, 7, '.', '')];
    }
}
