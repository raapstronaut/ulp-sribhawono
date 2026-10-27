<?php

namespace App\Services\Apkuk;

use App\Services\GoogleSheetValues;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Membaca dan memvalidasi APKUK_RAW.
 *
 * Satu-satunya tempat logika identitas (legacy_key) dan validasi sumber APKUK,
 * dipakai bersama oleh apkuk:sync dan apkuk:reconcile agar keduanya selalu menilai
 * sumber dengan cara yang sama.
 */
class ApkukSourceReader
{
    public const SOURCE = 'APPSHEET_LAMA';

    public const RANGE = "'APKUK_RAW'!A1:L";

    // Pengaman saat Google Sheets/IMPORTRANGE belum menampilkan seluruh data.
    // Audit pertama membuktikan 3.005 laporan, sehingga < 2.800 dianggap tidak lengkap.
    public const MIN_EXPECTED_ROWS = 2800;

    private const EXPECTED_HEADERS = [
        'ID', 'Tanggal', 'Petugas', 'Penyulang', 'Section',
        'No. Tiang/Gardu', 'Naik Dari', 'Terpasang bh',
        'Kurang bh', 'Status', 'Koordinat', 'Foto',
    ];

    public function __construct(private readonly GoogleSheetValues $sheets) {}

    /**
     * @throws ApkukSourceException
     */
    public function read(): ApkukSourceSnapshot
    {
        $credentialsPath = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (! $credentialsPath || ! is_file($credentialsPath) || ! $spreadsheetId) {
            throw new ApkukSourceException('Kredensial atau Spreadsheet ID di config/services.php belum lengkap.');
        }

        try {
            $rows = $this->sheets->get($credentialsPath, $spreadsheetId, self::RANGE, [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
                'dateTimeRenderOption' => 'SERIAL_NUMBER',
            ]);
        } catch (\Throwable $e) {
            throw new ApkukSourceException('Google Sheets gagal dibaca: '.$e->getMessage(), previous: $e);
        }

        return $this->parse($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     *
     * @throws ApkukSourceException
     */
    public function parse(array $rows): ApkukSourceSnapshot
    {
        if (count($rows) < 2) {
            throw new ApkukSourceException('APKUK_RAW kosong/tidak lengkap. PostgreSQL tidak diubah.');
        }

        foreach (self::EXPECTED_HEADERS as $index => $expected) {
            $actual = trim((string) ($rows[0][$index] ?? ''));
            if ($actual !== $expected) {
                throw new ApkukSourceException('Header kolom '.($index + 1)." salah: '{$actual}'. Seharusnya '{$expected}'.");
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
            $id = self::text($row[0]);
            $pole = self::text($row[5]);
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
            // Jika salah satunya diganti di sumber, pemeriksaan identitas hilang di
            // apkuk:sync MEMBATALKAN sinkronisasi, bukan diam-diam membuat duplikat.
            $legacyKey = self::legacyKey($id, $pole);

            if (isset($keyToSheetRow[$legacyKey])) {
                $issues[] = "Baris {$keyToSheetRow[$legacyKey]} dan {$sheetRow}: kombinasi ID + tiang terulang ({$id}, {$pole})";

                continue;
            }

            $keyToSheetRow[$legacyKey] = $sheetRow;
            $normalizedId = self::normalizeId($id);
            $sourceIds[$normalizedId] = ($sourceIds[$normalizedId] ?? 0) + 1;

            $date = self::parseDate($row[1]);
            if ($date === null) {
                $issues[] = "Baris {$sheetRow}: tanggal tidak dapat dibaca (".(string) $row[1].')';

                continue;
            }

            $terpasang = self::parseInteger($row[7]);
            $kurang = self::parseInteger($row[8]);

            if (self::text($row[7]) !== null && $terpasang === null) {
                $issues[] = "Baris {$sheetRow}: Terpasang bh bukan bilangan bulat (".(string) $row[7].')';
            }
            if (self::text($row[8]) !== null && $kurang === null) {
                $issues[] = "Baris {$sheetRow}: Kurang bh bukan bilangan bulat (".(string) $row[8].')';
            }
            if ($terpasang !== null && $terpasang < 0) {
                $negativeCounts[] = "Baris {$sheetRow}: Terpasang bh={$terpasang}";
            }
            if ($kurang !== null && $kurang < 0) {
                $negativeCounts[] = "Baris {$sheetRow}: Kurang bh={$kurang}";
            }

            [$latitude, $longitude] = self::parseCoordinates($row[10]);
            if (self::text($row[10]) !== null && $latitude === null) {
                $issues[] = "Baris {$sheetRow}: koordinat tidak valid (".(string) $row[10].')';
            }

            $records[] = [
                'source_id' => $id,
                'legacy_key' => $legacyKey,
                'tanggal' => $date,
                'petugas' => self::text($row[2]),
                'penyulang' => self::text($row[3]),
                'section' => self::text($row[4]),
                'no_tiang' => $pole,
                'naik_dari' => self::text($row[6]),
                'terpasang_bh' => $terpasang,
                'kurang_bh' => $kurang,
                'status' => self::text($row[9]),
                'koordinat' => self::text($row[10]),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'foto' => self::text($row[11]),
                'sumber_input' => self::SOURCE,
                'source_missing_at' => null,
                'source_row_number' => $sheetRow,
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return new ApkukSourceSnapshot($reportCount, $records, $keyToSheetRow, $sourceIds, $issues, $negativeCounts);
    }

    public static function normalizeId(string $id): string
    {
        return mb_strtoupper($id);
    }

    public static function normalizePole(string $pole): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', $pole));
    }

    /**
     * Identitas stabil satu laporan: ID AppSheet + nomor tiang ternormalisasi.
     */
    public static function legacyKey(string $id, string $pole): string
    {
        return hash('sha256', self::SOURCE.'|'.self::normalizeId($id).'|'.self::normalizePole($pole));
    }

    private static function text(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? null : $s;
    }

    private static function parseInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $s = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.0+)?$/', $s)) {
            return null;
        }
        $n = (float) $s;
        if (! is_finite($n) || $n < -2147483648 || $n > 2147483647) {
            return null;
        }

        return (int) $n;
    }

    private static function parseDate(mixed $value): ?string
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

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function parseCoordinates(mixed $value): array
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return [null, null];
        }
        if (! preg_match('/^\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*$/', $s, $m)) {
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
