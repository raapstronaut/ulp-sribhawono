<?php

namespace App\Console\Commands;

use App\Models\AssetSourceRow;
use App\Models\GangguanSourceRow;
use App\Models\VegetasiSourceRow;
use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSourceSheets extends Command
{
    protected $signature = 'sources:import
                            {--replace : Hapus data staging lama sebelum import}';

    protected $description = 'Import ASSET_RAW, VEGETASI_RAW, GANGGUAN_RAW dari Google Sheets ke PostgreSQL';

    /**
     * Jumlah baris per batch insert.
     */
    private const BATCH_SIZE = 500;

    public function handle(): int
    {
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

        try {
            $client = new Client();
            $client->setAuthConfig($credentialsPath);
            $client->setScopes([
                Sheets::SPREADSHEETS_READONLY,
            ]);

            $service = new Sheets($client);
        } catch (\Throwable $e) {
            $this->error('Gagal menginisialisasi Google Sheets client: '.$e->getMessage());

            return self::FAILURE;
        }

        $hasError = false;

        foreach ($this->sourceDefinitions() as $definition) {
            if (! $this->importSource($service, $spreadsheetId, $definition)) {
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
     * @return list<array{sheet: string, model: class-string, column_count: int, headers: list<string>, db_columns: list<string>}>
     */
    private function sourceDefinitions(): array
    {
        return [
            [
                'sheet' => 'ASSET_RAW',
                'model' => AssetSourceRow::class,
                'column_count' => 10,
                'headers' => [
                    'ID_ASET', 'NAMA_ASET', 'JENIS_ASET', 'PENYULANG',
                    'SECTION_ID', 'POSKO_ID', 'LATITUDE', 'LONGITUDE',
                    'FOTO_URL', 'STATUS',
                ],
                'db_columns' => [
                    'id_aset_raw', 'nama_aset_raw', 'jenis_aset_raw', 'penyulang_raw',
                    'section_id_raw', 'posko_id_raw', 'latitude_raw', 'longitude_raw',
                    'foto_url_raw', 'status_raw',
                ],
            ],
            [
                'sheet' => 'VEGETASI_RAW',
                'model' => VegetasiSourceRow::class,
                'column_count' => 20,
                // Spreadsheet memiliki dua kolom KETERANGAN (posisi M dan T).
                // Validasi berdasarkan posisi; mapping: M → keterangan_m_raw, T → keterangan_t_raw.
                'headers' => [
                    'ID_POHON', 'NAMA_POHON', 'PENYULANG', 'ID_ASET', 'NAMA_TIANG',
                    'JENIS_POHON', 'LATITUDE', 'LONGITUDE', 'JARAK_POHON', 'KONDISI',
                    'TANGGAL_SURVEY', 'SECTION_ID', 'KETERANGAN', 'STATUS',
                    'TANGGAL_INSPEKSI_TERAKHIR', 'TANGGAL_ROW_TERAKHIR',
                    'JENIS_ROW_TERAKHIR', 'KONDISI_TERAKHIR_AGUSTUS',
                    'FOTO_REF', 'KETERANGAN',
                ],
                'db_columns' => [
                    'id_pohon_raw', 'nama_pohon_raw', 'penyulang_raw', 'id_aset_raw',
                    'nama_tiang_raw', 'jenis_pohon_raw', 'latitude_raw', 'longitude_raw',
                    'jarak_pohon_raw', 'kondisi_raw', 'tanggal_survey_raw', 'section_id_raw',
                    'keterangan_m_raw', 'status_raw', 'tanggal_inspeksi_terakhir_raw',
                    'tanggal_row_terakhir_raw', 'jenis_row_terakhir_raw',
                    'kondisi_terakhir_agustus_raw', 'foto_ref_raw', 'keterangan_t_raw',
                ],
            ],
            [
                'sheet' => 'GANGGUAN_RAW',
                'model' => GangguanSourceRow::class,
                'column_count' => 24,
                'headers' => [
                    'ID_GANGGUAN', 'PENYULANG', 'PERALATAN', 'ULP',
                    'TANGGAL_KEJADIAN', 'KODE_GANGGUAN', 'URAIAN_PENYEBAB', 'PENYEBAB',
                    'LEPAS', 'MASUK', 'BEBAN_AMP', 'RELE_KERJA',
                    'ARUS_GANGGUAN_AMP', 'LAMA_PADAM_MENIT', 'ENS_KWH', 'SIFAT_GANGGUAN',
                    'ID_ASET', 'SECTION_ID', 'POSKO_ID', 'LATITUDE',
                    'LONGITUDE', 'JENIS_HEWAN', 'FOTO_REF', 'SUMBER_DATA',
                ],
                'db_columns' => [
                    'id_gangguan_raw', 'penyulang_raw', 'peralatan_raw', 'ulp_raw',
                    'tanggal_kejadian_raw', 'kode_gangguan_raw', 'uraian_penyebab_raw',
                    'penyebab_raw', 'lepas_raw', 'masuk_raw', 'beban_amp_raw',
                    'rele_kerja_raw', 'arus_gangguan_amp_raw', 'lama_padam_menit_raw',
                    'ens_kwh_raw', 'sifat_gangguan_raw', 'id_aset_raw', 'section_id_raw',
                    'posko_id_raw', 'latitude_raw', 'longitude_raw', 'jenis_hewan_raw',
                    'foto_ref_raw', 'sumber_data_raw',
                ],
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
    private function importSource(Sheets $service, string $spreadsheetId, array $definition): bool
    {
        $sheetName = $definition['sheet'];
        $modelClass = $definition['model'];
        $columnCount = $definition['column_count'];
        $expectedHeaders = $definition['headers'];
        $dbColumns = $definition['db_columns'];

        $this->newLine();
        $this->line(str_repeat('═', 60));
        $this->info("Import: {$sheetName}");
        $this->line(str_repeat('─', 60));

        // ─── Phase 1: Fetch dari Google Sheets ───

        try {
            $response = $service->spreadsheets_values->get(
                $spreadsheetId,
                "{$sheetName}!A:ZZ"
            );
        } catch (\Throwable $e) {
            $this->error("Gagal membaca sheet \"{$sheetName}\": {$e->getMessage()}");

            return false;
        }

        $allRows = $response->getValues() ?? [];

        if ($allRows === []) {
            $this->error("Sheet \"{$sheetName}\" kosong.");

            return false;
        }

        // ─── Phase 2: Validasi header berdasarkan posisi ───

        $header = array_map(
            fn ($value) => $this->cleanString($value),
            array_shift($allRows)
        );

        if (count($header) !== $columnCount) {
            $this->error(
                "Jumlah kolom tidak sesuai: expected {$columnCount}, actual ".count($header)
            );
            $this->line('Header terbaca: '.implode(' | ', $header));

            return false;
        }

        if ($header !== $expectedHeaders) {
            $this->error("Header \"{$sheetName}\" tidak sesuai.");
            $this->line('Expected: '.implode(' | ', $expectedHeaders));
            $this->line('Actual:   '.implode(' | ', $header));

            for ($i = 0; $i < $columnCount; $i++) {
                if ($header[$i] !== $expectedHeaders[$i]) {
                    $this->warn("  Kolom ".($i + 1).": expected \"{$expectedHeaders[$i]}\", actual \"{$header[$i]}\"");
                }
            }

            return false;
        }

        // ─── Phase 3: Parse semua baris ke buffer ───

        $now = now();
        $buffer = [];
        $skipped = 0;

        foreach ($allRows as $index => $row) {
            // +2: index mulai 0, baris 1 adalah header.
            $sourceRowNumber = $index + 2;

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
