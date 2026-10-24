<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApkuk extends Command
{
    protected $signature = 'apkuk:sync {--dry-run : Validasi saja, tanpa menyimpan ke PostgreSQL}';

    protected $description = 'Sinkronisasi APKUK_RAW dari MASTER_DATA ke tabel apkuks';

    // Pengaman saat Google Sheets/IMPORTRANGE belum menampilkan seluruh data.
    // Audit pertama membuktikan 3.005 laporan, sehingga < 2.800 dianggap tidak lengkap.
    private const MIN_EXPECTED_ROWS = 2800;

    public function handle(): int
    {
        $credentialsPath = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (!$credentialsPath || !is_file($credentialsPath) || !$spreadsheetId) {
            $this->error('Kredensial atau Spreadsheet ID di config/services.php belum lengkap.');
            return self::FAILURE;
        }

        try {
            $client = new Client();
            $client->setAuthConfig($credentialsPath);
            $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
            $sheets = new Sheets($client);

            $response = $sheets->spreadsheets_values->get(
                $spreadsheetId,
                "'APKUK_RAW'!A1:L",
                [
                    'valueRenderOption' => 'UNFORMATTED_VALUE',
                    'dateTimeRenderOption' => 'SERIAL_NUMBER',
                ]
            );
            $rows = $response->getValues() ?? [];
        } catch (\Throwable $e) {
            $this->error('Google Sheets gagal dibaca: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (count($rows) < 2) {
            $this->error('APKUK_RAW kosong/tidak lengkap. PostgreSQL tidak diubah.');
            return self::FAILURE;
        }

        $expectedHeaders = [
            'ID', 'Tanggal', 'Petugas', 'Penyulang', 'Section',
            'No. Tiang/Gardu', 'Naik Dari', 'Terpasang bh',
            'Kurang bh', 'Status', 'Koordinat', 'Foto',
        ];

        foreach ($expectedHeaders as $index => $expected) {
            $actual = trim((string) ($rows[0][$index] ?? ''));
            if ($actual !== $expected) {
                $this->error("Header kolom " . ($index + 1) . " salah: '{$actual}'. Seharusnya '{$expected}'.");
                return self::FAILURE;
            }
        }

        $records = [];
        $keyToSheetRow = [];
        $sourceIds = [];
        $issues = [];
        $negativeCounts = [];
        $reportCount = 0;
        $now = now();

        foreach (array_slice($rows, 1) as $index => $row) {
            $sheetRow = $index + 2;
            $row = array_pad($row, 12, null);

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $reportCount++;
            $id = $this->text($row[0]);
            $pole = $this->text($row[5]);
            if ($id === null || $pole === null) {
                $issues[] = "Baris {$sheetRow}: ID atau No. Tiang/Gardu kosong";
                continue;
            }

            if (mb_strlen($id) > 255 || mb_strlen($pole) > 255) {
                $issues[] = "Baris {$sheetRow}: ID atau nomor tiang terlalu panjang";
                continue;
            }

            // 1 sumber ID bisa dipakai beberapa laporan (contoh A150).
            // Kombinasi ID + tiang menjaga kedua laporan tetap terpisah.
            // Jika salah satunya diganti di sumber, pemeriksaan key hilang di bawah
            // akan MEMBATALKAN sinkronisasi, bukan diam-diam membuat duplikat.
            $normalizedId = mb_strtoupper($id);
            $normalizedPole = mb_strtoupper((string) preg_replace('/\s+/u', ' ', $pole));
            $legacyKey = hash('sha256', 'APPSHEET_LAMA|' . $normalizedId . '|' . $normalizedPole);

            if (isset($keyToSheetRow[$legacyKey])) {
                $issues[] = "Baris {$keyToSheetRow[$legacyKey]} dan {$sheetRow}: kombinasi ID + tiang terulang ({$id}, {$pole})";
                continue;
            }

            $keyToSheetRow[$legacyKey] = $sheetRow;
            $sourceIds[$normalizedId] = ($sourceIds[$normalizedId] ?? 0) + 1;

            $date = $this->parseDate($row[1]);
            if ($date === null) {
                $issues[] = "Baris {$sheetRow}: tanggal tidak dapat dibaca (" . (string) $row[1] . ')';
                continue;
            }

            $terpasang = $this->parseInteger($row[7]);
            $kurang = $this->parseInteger($row[8]);

            if ($this->text($row[7]) !== null && $terpasang === null) {
                $issues[] = "Baris {$sheetRow}: Terpasang bh bukan bilangan bulat (" . (string) $row[7] . ')';
            }
            if ($this->text($row[8]) !== null && $kurang === null) {
                $issues[] = "Baris {$sheetRow}: Kurang bh bukan bilangan bulat (" . (string) $row[8] . ')';
            }
            if ($terpasang !== null && $terpasang < 0) {
                $negativeCounts[] = "Baris {$sheetRow}: Terpasang bh={$terpasang}";
            }
            if ($kurang !== null && $kurang < 0) {
                $negativeCounts[] = "Baris {$sheetRow}: Kurang bh={$kurang}";
            }

            [$latitude, $longitude] = $this->parseCoordinates($row[10]);
            if ($this->text($row[10]) !== null && $latitude === null) {
                $issues[] = "Baris {$sheetRow}: koordinat tidak valid (" . (string) $row[10] . ')';
            }

            $records[] = [
                'source_id' => $id,
                'legacy_key' => $legacyKey,
                'tanggal' => $date,
                'petugas' => $this->text($row[2]),
                'penyulang' => $this->text($row[3]),
                'section' => $this->text($row[4]),
                'no_tiang' => $pole,
                'naik_dari' => $this->text($row[6]),
                'terpasang_bh' => $terpasang,
                'kurang_bh' => $kurang,
                'status' => $this->text($row[9]),
                'koordinat' => $this->text($row[10]),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'foto' => $this->text($row[11]),
                'sumber_input' => 'APPSHEET_LAMA',
                'source_missing_at' => null,
                'source_row_number' => $sheetRow,
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $totalIds = count($sourceIds);
        $duplicatedSourceIds = array_filter($sourceIds, fn ($v) => $v > 1);
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
            $this->warn('ID AppSheet yang digunakan pada beberapa laporan: ' . implode(', ', array_keys($duplicatedSourceIds)));
        }
        if ($negativeCounts !== []) {
            $this->warn('Ada nilai jumlah negatif di sumber. Nilai aslinya dipertahankan:');
            foreach (array_slice($negativeCounts, 0, 10) as $item) {
                $this->line(' - ' . $item);
            }
        }
        if ($issues !== []) {
            $this->error('Ada data tidak valid; seluruh proses dibatalkan supaya tidak ada laporan terlewat atau ditimpa:');
            foreach (array_slice($issues, 0, 20) as $issue) {
                $this->line(' - ' . $issue);
            }
            return self::FAILURE;
        }
        if ($reportCount < self::MIN_EXPECTED_ROWS || count($records) !== $reportCount) {
            $this->error('Jumlah data sumber turun/berbeda dari hasil validasi. PostgreSQL tidak diubah.');
            return self::FAILURE;
        }

        try {
            // Data AppSheet lama tidak boleh menimpa data input aplikasi mobile.
            $existing = DB::table('apkuks')
                ->where('sumber_input', 'APPSHEET_LAMA')
                ->whereNotNull('legacy_key')
                ->whereNull('source_missing_at')
                ->get(['legacy_key', 'source_id', 'no_tiang']);

            $incoming = array_fill_keys(array_keys($keyToSheetRow), true);

            $missing = $existing->filter(
                fn ($old) => !isset($incoming[$old->legacy_key])
            );

            if ($missing->isNotEmpty()) {
                $this->error($missing->count() . ' identitas lama di PostgreSQL tidak ditemukan di sumber terbaru.');

                foreach ($missing as $old) {
                    $this->line(
                        ' - ID: ' . ($old->source_id ?? '(kosong)') .
                        ' | Tiang: ' . ($old->no_tiang ?? '(kosong)')
                    );
                }

                $this->error('Sync dibatalkan demi mencegah duplikat.');
                return self::FAILURE;
            }
            if ($this->option('dry-run')) {
                $this->info('DRY RUN BERHASIL. Data PostgreSQL tidak diubah.');
                return self::SUCCESS;
            }

            $before = DB::table('apkuks')->where('sumber_input', 'APPSHEET_LAMA')->count();
            $toUpdate = array_values(array_diff(array_keys($records[0]), [
                'legacy_key', 'created_at', 'sumber_input',
            ]));

            DB::transaction(function () use ($records, $toUpdate): void {
                foreach (array_chunk($records, 200) as $chunk) {
                    DB::table('apkuks')->upsert($chunk, ['legacy_key'], $toUpdate);
                }
            });

            $after = DB::table('apkuks')->where('sumber_input', 'APPSHEET_LAMA')->count();
            $this->info("SYNC APKUK BERHASIL. Total data AppSheet lama: {$after}; bertambah: " . ($after - $before) . '.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sinkronisasi gagal; transaksi database dibatalkan: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function text(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : $s;
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $s = trim((string) $value);
        if (!preg_match('/^-?\d+(?:\.0+)?$/', $s)) {
            return null;
        }
        $n = (float) $s;
        if (!is_finite($n) || $n < -2147483648 || $n > 2147483647) {
            return null;
        }
        return (int) $n;
    }

    private function parseDate(mixed $value): ?string
    {
        if (is_int($value) || is_float($value) || preg_match('/^\d{5}(?:\.0+)?$/', trim((string) $value))) {
            $n = (float) $value;
            if ($n < 40000 || $n > 80000) {
                return null;
            }
            $days = (int) floor($n);
            return (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
                ->modify("+{$days} days")
                ->format('Y-m-d');
        }

        $s = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $s, $m)) {
            // Jika kedua angka <= 12, format bisa ambigu DD/MM atau MM/DD.
            // Jangan menebak tanggal: laporkan masalah agar diperiksa.
            $first = (int) $m[1];
            $second = (int) $m[2];
            if ($first <= 12 && $second <= 12) {
                return null;
            }
            if ($first > 12 && checkdate($second, $first, (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $second, $first);
            }
            if ($second > 12 && checkdate($first, $second, (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $first, $second);
            }
        }
        return null;
    }

    private function parseCoordinates(mixed $value): array
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return [null, null];
        }
        if (!preg_match('/^\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*$/', $s, $m)) {
            return [null, null];
        }
        $lat = (float) $m[1];
        $lon = (float) $m[2];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return [null, null];
        }
        return [$m[1], $m[2]];
    }
}
