<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsSyncResult;
use App\Services\GoogleSheetValues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncGangguanPusat extends Command
{
    use ReportsSyncResult;

    protected $signature = 'gangguan:sync {--dry-run : Periksa data tanpa menulis ke PostgreSQL}';
    protected $description = 'Sinkronkan gangguan PLN pusat dari Google Sheets ke PostgreSQL';

    public function handle(GoogleSheetValues $sheets): int
    {
        $credentials = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (!$credentials || !is_file($credentials) || !$spreadsheetId) {
            $this->error('Konfigurasi Google Sheets belum lengkap.');
            return self::FAILURE;
        }

        try {
            $rows = $sheets->get(
                $credentials,
                $spreadsheetId,
                "'GANGGUAN_PUSAT_IMPORT'!A1:CY",
                [
                    'valueRenderOption' => 'UNFORMATTED_VALUE',
                    'dateTimeRenderOption' => 'SERIAL_NUMBER',
                ]
            );
        } catch (\Throwable $e) {
            $this->error('Gagal membaca Google Sheets: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (count($rows) < 2) {
            $this->error('Hasil impor kosong atau belum selesai diperbarui. PostgreSQL tidak diubah.');
            return self::FAILURE;
        }

        $header = $rows[0];
        $expected = [
            0 => 'Penyulang', 2 => 'ULP Koreksi', 3 => 'Tanggal',
            25 => 'No: Transaksi APKT', 51 => 'NoTi',
            79 => 'FOTO CORRECTIVE', 80 => 'FOTO GANGGUAN',
            91 => 'HEWAN', 96 => 'ENS APKT', 98 => 'KEYPOINT TERGANGGU',
        ];
        foreach ($expected as $index => $name) {
            if (trim((string) ($header[$index] ?? '')) !== $name) {
                $this->error("Header kolom " . ($index + 1) . " tidak cocok: {$name}. Tidak ada data disimpan.");
                return self::FAILURE;
            }
        }

        $records = [];
        $ids = [];
        $withoutId = [];
        $badDates = [];
        $badNumbers = [];
        $total = 0;
        $now = now();

        foreach (array_slice($rows, 1) as $index => $row) {
            $sheetRow = $index + 2;
            if (strtoupper(trim((string) ($row[2] ?? ''))) !== 'SRIBAWONO') {
                continue;
            }
            $total++;

            $id = trim((string) ($row[25] ?? ''));
            $id = trim((string) preg_replace('/\*+\s*$/', '', $id));
            if ($id === '' || str_starts_with($id, '#') || strlen($id) > 100) {
                $withoutId[] = [$sheetRow, $this->text($row, 3) ?? '', $this->text($row, 0) ?? '', $this->text($row, 1) ?? ''];
                continue;
            }
            if (isset($ids[$id])) {
                $this->error("ID APKT duplikat: {$id} (baris {$ids[$id]} dan {$sheetRow}). Sync dibatalkan.");
                return self::FAILURE;
            }
            $ids[$id] = $sheetRow;

            $date = $this->dateValue($row[3] ?? null);
            if ($date === null) {
                $badDates[] = [$sheetRow, $id, $this->text($row, 3) ?? ''];
                continue;
            }

            $records[] = [
                'id_gangguan' => $id,
                'tanggal_kejadian' => $date,
                'penyulang' => $this->text($row, 0),
                'ulp' => $this->text($row, 2),
                'peralatan' => $this->text($row, 1),
                'kategori_proteksi_pusat' => $this->text($row, 31),
                'kode_gangguan' => $this->text($row, 4),
                'sifat_gangguan' => $this->text($row, 28),
                'penyebab' => $this->text($row, 6),
                'jenis_hewan' => $this->text($row, 91),
                'uraian_penyebab' => $this->text($row, 5),
                'no_tiang' => $this->text($row, 51),
                'keypoint_pusat' => $this->text($row, 98),
                'lepas' => $this->timeValue($row[7] ?? null),
                'masuk' => $this->timeValue($row[11] ?? null),
                'lama_padam_menit' => $this->decimalValue($row[14] ?? null, 'lama_padam_menit', $sheetRow, $badNumbers),
                'ens_kwh' => $this->decimalValue($row[15] ?? null, 'ens_kwh', $sheetRow, $badNumbers),
                'ens_apkt_kwh' => $this->decimalValue($row[96] ?? null, 'ens_apkt_kwh', $sheetRow, $badNumbers),
                'beban_amp' => $this->decimalValue($row[8] ?? null, 'beban_amp', $sheetRow, $badNumbers),
                'beban_setelah_amp' => $this->decimalValue($row[12] ?? null, 'beban_setelah_amp', $sheetRow, $badNumbers),
                'rele_kerja' => $this->text($row, 9),
                'arus_gangguan_amp' => $this->decimalValue($row[10] ?? null, 'arus_gangguan_amp', $sheetRow, $badNumbers),
                'material_rusak' => $this->text($row, 24),
                'tindakan_corrective' => $this->text($row, 48),
                'tindakan_preventive' => $this->text($row, 49),
                'keterangan_temuan' => $this->text($row, 86),
                'foto_gangguan_url' => $this->text($row, 80),
                'foto_corrective_url' => $this->text($row, 79),
                'sumber_data' => 'PLN_PUSAT',
                'last_synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Gangguan SRIBAWONO terbaca', $total],
            ['Siap disinkronkan', count($records)],
            ['Tanpa ID APKT', count($withoutId)],
            ['Tanggal tidak terbaca', count($badDates)],
            ['Nilai angka tidak valid (disimpan NULL)', count($badNumbers)],
        ]);

        if ($withoutId) {
            $this->warn('Tanpa ID APKT (dilewati; akan dicoba lagi saat sync berikutnya):');
            $this->table(['Baris', 'Tanggal', 'Penyulang', 'Peralatan'], array_slice($withoutId, 0, 10));
            $this->noteHiddenRows(count($withoutId), 10);
        }
        if ($badDates) {
            $this->warn('Tanggal tidak valid (baris dilewati):');
            $this->table(['Baris', 'ID APKT', 'Tanggal'], array_slice($badDates, 0, 10));
            $this->noteHiddenRows(count($badDates), 10);
        }
        if ($badNumbers) {
            $this->warn('Angka tidak valid (nilai numeriknya menjadi NULL, sumber tidak diubah):');
            $this->table(['Baris', 'Kolom', 'Nilai asli'], array_slice($badNumbers, 0, 10));
            $this->noteHiddenRows(count($badNumbers), 10);
        }

        if ($total === 0 || $records === []) {
            $this->error('Tidak ada record valid. PostgreSQL tidak diubah.');
            return self::FAILURE;
        }

        try {
            $before = DB::table('gangguans')->count();
            if ($before >= 100 && $total < $before * 0.8) {
                $this->error('Jumlah sumber turun drastis dibanding PostgreSQL. Sync dihentikan untuk keamanan.');
                return self::FAILURE;
            }
            if ($this->option('dry-run')) {
                $this->info('DRY RUN BERHASIL. PostgreSQL tidak diubah.');
                return self::SUCCESS;
            }

            $updatedColumns = array_values(array_diff(array_keys($records[0]), ['id_gangguan', 'created_at']));
            DB::transaction(function () use ($records, $updatedColumns): void {
                foreach (array_chunk($records, 200) as $batch) {
                    DB::table('gangguans')->upsert($batch, ['id_gangguan'], $updatedColumns);
                }
            });
            $after = DB::table('gangguans')->count();
            $this->info("SYNC BERHASIL. Total PostgreSQL: {$after}; bertambah: " . ($after - $before) . '.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Sync gagal; transaksi database dibatalkan: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function noteHiddenRows(int $total, int $shown): void
    {
        if ($total > $shown) {
            $this->line(' … dan ' . ($total - $shown) . ' baris lainnya (tidak ditampilkan)');
        }
    }

    private function text(array $row, int $index): ?string
    {
        $value = trim((string) ($row[$index] ?? ''));
        return $value === '' ? null : $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $days = (int) floor($value);
            if ($days < 40000 || $days > 80000) {
                return null;
            }
            return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))
                ->modify("+{$days} days")->format('Y-m-d');
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (!preg_match('/^(\d{1,2})\s+([[:alpha:]]+)\s+(\d{4})$/u', $s, $m)) {
            return null;
        }
        $months = [
            'jan' => 'Jan', 'feb' => 'Feb', 'mar' => 'Mar', 'apr' => 'Apr',
            'mei' => 'May', 'may' => 'May', 'jun' => 'Jun', 'jul' => 'Jul',
            'agu' => 'Aug', 'aug' => 'Aug', 'sep' => 'Sep', 'okt' => 'Oct',
            'oct' => 'Oct', 'nov' => 'Nov', 'des' => 'Dec', 'dec' => 'Dec',
        ];
        $month = $months[strtolower($m[2])] ?? null;
        if ($month === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!j M Y', "{$m[1]} {$month} {$m[3]}");
        return $date === false ? null : $date->format('Y-m-d');
    }

    private function timeValue(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $fraction = $value - floor($value);
            $seconds = ((int) round($fraction * 86400)) % 86400;
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function decimalValue(mixed $value, string $field, int $row, array &$issues): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $raw = trim((string) $value);
        $s = str_replace(["\xc2\xa0", ' '], '', $raw);
        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+,\d+$/', $s)) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^-?\d{1,3}(?:,\d{3})+\.\d+$/', $s)) {
            $s = str_replace(',', '', $s);
        } elseif (preg_match('/^-?\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        }
        if (is_numeric($s)) {
            return $s;
        }
        $issues[] = [$row, $field, $raw];
        return null;
    }
}
