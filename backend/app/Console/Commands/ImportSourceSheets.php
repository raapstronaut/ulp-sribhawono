<?php

namespace App\Console\Commands;

use App\Models\AssetSourceRow;
use App\Models\SectionPicSourceRow;
use App\Models\VegetasiSourceRow;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\GoogleSheetValues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSourceSheets extends Command
{
    protected $signature = 'sources:import
                            {--replace : Hapus data staging lama sebelum import}
                            {--only=* : Hanya sheet tertentu (ASSET_RAW, VEGETASI_RAW, SECTION_PIC_RAW); default semua}';

    protected $description = 'Import ASSET_RAW, VEGETASI_RAW, SECTION_PIC_RAW, KEYPOINT_RAW dari Google Sheets ke PostgreSQL';

    /**
     * Jumlah baris per batch insert.
     */
    private const BATCH_SIZE = 500;

    public function handle(GoogleSheetValues $sheets): int
    {
        // Divalidasi sebelum menghubungi Google agar salah ketik tidak mengimport (atau --replace) semua sheet.
        $known = array_column($this->sourceDefinitions(), 'sheet');
        $only = array_values(array_unique(array_map(fn ($sheet) => mb_strtoupper(trim((string) $sheet)), (array) $this->option('only'))));
        if ($unknown = array_diff($only, $known)) {
            $this->error('Sheet tidak dikenal untuk --only: '.implode(', ', $unknown).'. Pilihan: '.implode(', ', $known).'.');

            return self::FAILURE;
        }

        $credentialsPath = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (! $credentialsPath || ! is_file($credentialsPath)) {
            $this->error("Credential JSON tidak ditemukan: {$credentialsPath}");

            return self::FAILURE;
        }

        if (! $spreadsheetId) {
            $this->error('GOOGLE_SHEETS_SPREADSHEET_ID belum diisi.');

            return self::FAILURE;
        }

        $hasError = false;

        foreach ($this->sourceDefinitions() as $definition) {
            if ($only !== [] && ! in_array($definition['sheet'], $only, true)) {
                continue;
            }
            if (! $this->importSource($sheets, $credentialsPath, $spreadsheetId, $definition)) {
                $hasError = true;
            }
        }

        $this->newLine();

        if ($hasError) {
            $this->error('Satu atau lebih source gagal diimport.');
        } else {
            $this->info('Semua source berhasil diimport.');
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Definisi per source: sheet name, model, expected headers, DB column mapping.
     *
     * Header divalidasi berdasarkan posisi dan jumlah kolom.
     * DB column mapping menggunakan urutan posisi yang sama dengan header.
     *
     * @return list<array{sheet: string, model: class-string, column_count: int, headers: list<string>, db_columns: list<string>, skip_leading_blanks?: bool}>
     */
    private function sourceDefinitions(): array
    {
        return [
            [
                'sheet' => 'ASSET_RAW',
                'model' => AssetSourceRow::class,
                'column_count' => 7,
                'headers' => [
                    'ID_ASET', 'NAMA_ASET', 'JENIS_ASET', 'PENYULANG',
                    'LATITUDE', 'LONGITUDE', 'STATUS',
                ],
                'db_columns' => [
                    'id_aset_raw', 'nama_aset_raw', 'jenis_aset_raw', 'penyulang_raw',
                    'latitude_raw', 'longitude_raw', 'status_raw',
                ],
            ],
            [
                'sheet' => 'VEGETASI_RAW',
                'model' => VegetasiSourceRow::class,
                'column_count' => 28,
                'headers' => [
                    'NO', 'NAMA POHON', 'UP3', 'ULP', 'PENYULANG',
                    'ID JTM', 'ID TIANG', 'NAMA TIANG', 'JENIS POHON',
                    'PERTUMBUHAN POHON', 'JARAK AMAN', 'JARAK BAHAYA',
                    'KONDISI', 'SECTION', 'EAM', 'LATITUDE', 'LONGITUDE',
                    'JARAK POHON', 'JARAK POHON SAAT INI', 'IZIN PADAM',
                    'IZIN TEBANG BAWAH', 'TANGGAL UPDATE', 'TANGGAL PERIKSA',
                    'TANGGAL PERKIRAAN BAHAYA', 'USER PEMBUAT', 'USER UPDATE',
                    'KETERANGAN', 'ID POHON',
                ],
                'db_columns' => [
                    'no_raw', 'nama_pohon_raw', 'up3_raw', 'ulp_raw', 'penyulang_raw',
                    'id_jtm_raw', 'id_tiang_raw', 'nama_tiang_raw', 'jenis_pohon_raw',
                    'pertumbuhan_pohon_raw', 'jarak_aman_raw', 'jarak_bahaya_raw',
                    'kondisi_raw', 'section_raw', 'eam_raw', 'latitude_raw', 'longitude_raw',
                    'jarak_pohon_raw', 'jarak_pohon_saat_ini_raw', 'izin_padam_raw',
                    'izin_tebang_bawah_raw', 'tanggal_update_raw', 'tanggal_periksa_raw',
                    'tanggal_perkiraan_bahaya_raw', 'user_pembuat_raw', 'user_update_raw',
                    'keterangan_raw', 'id_pohon_raw',
                ],
            ],
            [
                'sheet' => 'SECTION_PIC_RAW',
                'model' => SectionPicSourceRow::class,
                'column_count' => 3,
                'headers' => [
                    'PENYULANG', 'PEMUTUS', 'POSKO PIC',
                ],
                'db_columns' => [
                    'penyulang_raw', 'pemutus_raw', 'posko_pic_raw',
                ],
                // Source file may have leading blank rows before the header row.
                'skip_leading_blanks' => true,
            ],
        ];
    }

    /**
     * Import satu source sheet ke staging table.
     *
     * Alur atomic:
     * 1. Fetch dari Google Sheets (di luar transaction).
     * 2. Validasi header berdasarkan posisi dan jumlah kolom.
     * 3. Parse semua baris ke buffer (di luar transaction).
     * 4. DB transaction: delete lama (jika --replace) + batch insert.
     *
     * Jika fetch/validasi gagal → tidak ada perubahan DB.
     * Jika insert gagal → rollback, data lama tetap utuh.
     */
    private function importSource(GoogleSheetValues $sheets, string $credentialsPath, string $spreadsheetId, array $definition): bool
    {
        $sheetName = $definition['sheet'];
        $modelClass = $definition['model'];
        $columnCount = $definition['column_count'];
        $expectedHeaders = $definition['headers'];
        $dbColumns = $definition['db_columns'];
        $skipLeadingBlanks = $definition['skip_leading_blanks'] ?? false;

        $this->newLine();
        $this->line(str_repeat('═', 60));
        $this->info("Import: {$sheetName}");
        $this->line(str_repeat('─', 60));

        // ─── Phase 1: Fetch dari Google Sheets ───

        // Pembaca read-only (scope SPREADSHEETS_READONLY); command ini tidak pernah menulis ke Google Sheets.
        try {
            $allRows = $sheets->get($credentialsPath, $spreadsheetId, "{$sheetName}!A:ZZ");
        } catch (\Throwable $e) {
            $this->error("Gagal membaca sheet \"{$sheetName}\": {$e->getMessage()}");

            return false;
        }

        if ($allRows === []) {
            $this->error("Sheet \"{$sheetName}\" kosong.");

            return false;
        }

        // ─── Phase 2: Validasi header ───

        $resolved = $this->resolveHeader($allRows, $definition);

        if ($resolved['error'] !== null) {
            $this->error($resolved['error']);

            if ($resolved['detail'] !== null) {
                $this->line($resolved['detail']);
            }

            if ($resolved['warnings'] !== []) {
                foreach ($resolved['warnings'] as $warning) {
                    $this->warn($warning);
                }
            }

            return false;
        }

        $headerRowIndex = $resolved['header_row_index'];
        $allRows = $resolved['data_rows'];

        if ($headerRowIndex > 0) {
            $this->line("Melewati {$headerRowIndex} leading blank row(s).");
        }

        // ─── Phase 3: Parse semua baris ke buffer ───

        $now = now();
        $buffer = [];
        $skipped = 0;

        foreach ($allRows as $index => $row) {
            // headerRowIndex accounts for skipped leading rows.
            // +2: index mulai 0, +1 untuk header row sendiri, +1 untuk 1-based.
            $sourceRowNumber = $headerRowIndex + $index + 2;

            if ($this->isEmptyRow($row)) {
                $skipped++;

                continue;
            }

            $row = array_pad($row, $columnCount, null);
            $row = array_slice($row, 0, $columnCount);

            $record = [];

            foreach ($dbColumns as $colIndex => $dbColumn) {
                $record[$dbColumn] = $this->nullableString($row[$colIndex]);
            }

            $record['source_row_number'] = $sourceRowNumber;
            $record['imported_at'] = $now;
            $record['created_at'] = $now;
            $record['updated_at'] = $now;

            $buffer[] = $record;
        }

        $totalSourceRows = count($allRows);
        $totalToImport = count($buffer);

        if ($totalToImport === 0) {
            $this->warn("Tidak ada data untuk diimport dari \"{$sheetName}\".");

            return true;
        }

        if ($modelClass === SectionPicSourceRow::class && ! $this->guardSectionReferences($buffer)) {
            return false;
        }

        // ─── Phase 4: Atomic DB transaction ───

        try {
            DB::beginTransaction();

            if ($this->option('replace')) {
                $modelClass::query()->delete();
                $this->info("Data staging {$sheetName} lama dihapus.");
            }

            $imported = 0;

            foreach (array_chunk($buffer, self::BATCH_SIZE) as $chunk) {
                $modelClass::query()->insert($chunk);
                $imported += count($chunk);
            }

            DB::commit();

            $this->newLine();
            $this->table(
                ['Keterangan', 'Jumlah'],
                [
                    ['Baris sumber (setelah header)', $totalSourceRows],
                    ['Berhasil diimport', $imported],
                    ['Baris kosong dilewati', $skipped],
                    ['Total staging PostgreSQL', $modelClass::count()],
                ]
            );

            return true;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->error("Gagal import \"{$sheetName}\": {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Pengaman SECTION_PIC_RAW, satu-satunya sumber kepemilikan Section → Posko
     * (docs/SECTION_REFERENCE_CONTRACT.md). Dijalankan sebelum transaksi; bila menolak, staging lama utuh.
     *
     * - Tab ini snapshot utuh: bila staging sudah berisi, wajib --replace. Tanpa --replace baris baru
     *   bercampur dengan baris lama menurut nomor baris sumber, sehingga pewarisan PENYULANG (fill-down)
     *   menjadi salah dan baris tergandakan.
     * - Isi baru yang tidak menghasilkan satu pun Section terverifikasi tidak menggantikan referensi lama
     *   yang masih memiliki Section terverifikasi (mis. kolom tertukar tetapi header benar).
     * - Section yang tidak lagi terverifikasi atau berganti Posko dicetak untuk ditinjau.
     *
     * @param  list<array<string, mixed>>  $buffer  baris baru urut baris sumber
     */
    private function guardSectionReferences(array $buffer): bool
    {
        if (! $this->option('replace') && SectionPicSourceRow::query()->exists()) {
            $this->error('SECTION_PIC_RAW adalah snapshot satu tab dan staging sudah berisi: jalankan dengan --replace. Referensi lama tidak diubah.');

            return false;
        }

        $verified = fn (SectionPoskoMap $map) => collect($map->entries())
            ->whereNotNull('posko')
            ->mapWithKeys(fn (array $entry) => [$entry['section'].' ('.$entry['penyulang'].')' => $entry['posko']->nama_posko])
            ->all();
        $before = $verified(SectionPoskoMap::load());
        $after = $verified(SectionPoskoMap::fromRows(array_map(fn (array $row) => (object) $row, $buffer)));

        if ($after === [] && $before !== []) {
            $this->error('Isi SECTION_PIC_RAW baru tidak menghasilkan satu pun Section terverifikasi; '.count($before).' referensi lama dipertahankan. Validasi dulu dengan: php artisan pasila:section-references --file=<berkas.csv>');

            return false;
        }

        $this->line('Section terverifikasi: sebelum '.count($before).', sesudah '.count($after).'.');
        foreach (array_diff_key($before, $after) as $section => $posko) {
            $this->warn("  Tidak lagi terverifikasi: {$section}, sebelumnya Posko {$posko}");
        }
        foreach (array_intersect_key($after, $before) as $section => $posko) {
            if ($before[$section] !== $posko) {
                // Hanya ASCII: keluaran CLI di lingkungan ini membuang karakter non-ASCII seperti panah.
                $this->warn("  Berganti Posko: {$section}, dari {$before[$section]} ke {$posko}");
            }
        }

        return true;
    }

    /**
     * Resolve header dari raw rows.
     *
     * Aturan:
     * 1. Jika skip_leading_blanks → hanya melewati baris yang benar-benar kosong.
     *    Baris non-empty pertama WAJIB sama persis dengan expected header.
     *    Jika tidak cocok → error (tidak scan/melewati arbitrary non-header rows).
     * 2. Trailing extra columns: di-trim hanya jika SEMUA extra header columns kosong.
     *    Jika ada extra column non-empty → error.
     * 3. Jumlah kolom dan nama kolom harus persis cocok.
     *
     * @param  list<list<mixed>>  $allRows  Raw rows dari Google Sheets (header + data)
     * @param  array  $definition  Source definition
     * @return array{header_row_index: int, data_rows: list<list<mixed>>, error: ?string, detail: ?string, warnings: list<string>}
     */
    protected function resolveHeader(array $allRows, array $definition): array
    {
        $columnCount = $definition['column_count'];
        $expectedHeaders = $definition['headers'];
        $sheetName = $definition['sheet'];
        $skipLeadingBlanks = $definition['skip_leading_blanks'] ?? false;

        $headerRowIndex = 0;

        // ─── Step 1: Skip leading blank rows (strict) ───

        if ($skipLeadingBlanks) {
            while ($allRows !== [] && $this->isEmptyRow($allRows[0])) {
                array_shift($allRows);
                $headerRowIndex++;
            }
        }

        if ($allRows === []) {
            return [
                'header_row_index' => $headerRowIndex,
                'data_rows' => [],
                'error' => "Tidak ditemukan header pada sheet \"{$sheetName}\".",
                'detail' => null,
                'warnings' => [],
            ];
        }

        // ─── Step 2: Extract and clean header ───

        $header = array_map(
            fn ($value) => $this->cleanString($value),
            array_shift($allRows)
        );

        // ─── Step 3: Handle trailing extra columns ───
        // Hanya trim jika SEMUA extra header columns kosong.
        // Jika ada extra column non-empty → validation gagal.

        if (count($header) > $columnCount) {
            $extraColumns = array_slice($header, $columnCount);
            $nonEmptyExtra = array_filter($extraColumns, fn ($v) => $v !== '');

            if ($nonEmptyExtra !== []) {
                return [
                    'header_row_index' => $headerRowIndex,
                    'data_rows' => $allRows,
                    'error' => "Jumlah kolom tidak sesuai: expected {$columnCount}, actual ".count($header),
                    'detail' => 'Header terbaca: '.implode(' | ', $header),
                    'warnings' => ['Extra kolom non-empty: '.implode(' | ', $nonEmptyExtra)],
                ];
            }

            // Semua extra columns kosong → aman untuk di-trim
            $header = array_slice($header, 0, $columnCount);
        }

        // ─── Step 4: Validate column count ───

        if (count($header) !== $columnCount) {
            return [
                'header_row_index' => $headerRowIndex,
                'data_rows' => $allRows,
                'error' => "Jumlah kolom tidak sesuai: expected {$columnCount}, actual ".count($header),
                'detail' => 'Header terbaca: '.implode(' | ', $header),
                'warnings' => [],
            ];
        }

        // ─── Step 5: Validate header names ───

        if ($header !== $expectedHeaders) {
            $warnings = [];
            for ($i = 0; $i < $columnCount; $i++) {
                if ($header[$i] !== $expectedHeaders[$i]) {
                    $warnings[] = "  Kolom ".($i + 1).": expected \"{$expectedHeaders[$i]}\", actual \"{$header[$i]}\"";
                }
            }

            return [
                'header_row_index' => $headerRowIndex,
                'data_rows' => $allRows,
                'error' => "Header \"{$sheetName}\" tidak sesuai.",
                'detail' => "Expected: ".implode(' | ', $expectedHeaders)."\nActual:   ".implode(' | ', $header),
                'warnings' => $warnings,
            ];
        }

        // ─── Success ───

        return [
            'header_row_index' => $headerRowIndex,
            'data_rows' => $allRows,
            'error' => null,
            'detail' => null,
            'warnings' => [],
        ];
    }

    private function cleanString(mixed $value): string
    {
        return trim(
            preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->cleanString($value);

        return $value === '' ? null : $value;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->nullableString($value) !== null) {
                return false;
            }
        }

        return true;
    }
}
