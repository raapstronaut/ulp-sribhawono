<?php

namespace App\Console\Commands;

use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;

class ProfileSheetSources extends Command
{
    protected $signature = 'sheets:profile-sources
                            {--sheet=* : Profil sheet tertentu saja (default: semua)}';

    protected $description = 'Profiling read-only struktur ASSET_RAW, VEGETASI_RAW, GANGGUAN_RAW, SECTION_PIC_RAW, KEYPOINT_RAW dari Google Sheets';

    /**
     * Sheet yang akan diprofil secara default.
     */
    private const DEFAULT_SHEETS = [
        'ASSET_RAW',
        'VEGETASI_RAW',
        'GANGGUAN_RAW',
        'SECTION_PIC_RAW',
        'KEYPOINT_RAW',
    ];

    /**
     * Batas baris contoh yang ditampilkan.
     */
    private const SAMPLE_LIMIT = 3;

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

        $sheets = $this->option('sheet');
        if ($sheets === []) {
            $sheets = self::DEFAULT_SHEETS;
        }

        try {
            $client = new Client();
            $client->setAuthConfig($credentialsPath);
            $client->setScopes([
                Sheets::SPREADSHEETS_READONLY,
            ]);

            $service = new Sheets($client);

            $this->info('Spreadsheet ID: '.$spreadsheetId);
            $this->info('Jumlah sheet yang akan diprofil: '.count($sheets));
            $this->newLine();

            $hasError = false;

            foreach ($sheets as $sheetName) {
                if (! $this->profileSheet($service, $spreadsheetId, $sheetName)) {
                    $hasError = true;
                }
            }

            return $hasError ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function profileSheet(Sheets $service, string $spreadsheetId, string $sheetName): bool
    {
        $this->line(str_repeat('═', 70));
        $this->info("📋 Sheet: {$sheetName}");
        $this->line(str_repeat('─', 70));

        try {
            $response = $service->spreadsheets_values->get(
                $spreadsheetId,
                "{$sheetName}!A:ZZ"
            );
        } catch (\Throwable $e) {
            $this->warn("⚠ Sheet \"{$sheetName}\" tidak ditemukan atau tidak dapat diakses.");
            $this->warn("  Detail: {$e->getMessage()}");
            $this->newLine();

            return false;
        }

        $allRows = $response->getValues() ?? [];

        if ($allRows === []) {
            $this->warn("⚠ Sheet \"{$sheetName}\" kosong (tidak ada data sama sekali).");
            $this->newLine();

            return true;
        }

        // Lewati leading blank rows (beberapa source memiliki blank rows
        // sebelum header, misalnya SECTION_PIC_RAW).
        $leadingBlanks = 0;
        while ($allRows !== [] && $this->isEmptyRow($allRows[0])) {
            array_shift($allRows);
            $leadingBlanks++;
        }

        if ($leadingBlanks > 0) {
            $this->warn("⚠ {$leadingBlanks} leading blank row(s) dilewati sebelum header.");
        }

        if ($allRows === []) {
            $this->warn("⚠ Sheet \"{$sheetName}\" hanya berisi baris kosong.");
            $this->newLine();

            return true;
        }

        $header = array_map(
            fn ($value) => $this->cleanString($value),
            array_shift($allRows)
        );

        $headerCount = count($header);

        // ─── Header warnings ───

        $this->profileHeader($sheetName, $header);

        // ─── Row statistics ───

        $dataRows = $allRows;
        $totalDataRows = count($dataRows);
        $emptyRows = 0;
        $overflowRows = 0;

        foreach ($dataRows as $row) {
            if ($this->isEmptyRow($row)) {
                $emptyRows++;
            }

            if (count($row) > $headerCount) {
                $overflowRows++;
            }
        }

        $this->newLine();
        $this->table(
            ['Metrik', 'Nilai'],
            [
                ['Jumlah kolom (header)', $headerCount],
                ['Total baris setelah header', $totalDataRows],
                ['Baris berisi data', $totalDataRows - $emptyRows],
                ['Baris kosong', $emptyRows],
            ]
        );

        if ($overflowRows > 0) {
            $this->warn("⚠ {$overflowRows} baris memiliki kolom lebih banyak dari header ({$headerCount} kolom).");
        }

        // ─── Sample rows ───

        $this->profileSampleRows($header, $dataRows);

        $this->newLine();

        return true;
    }

    private function profileHeader(string $sheetName, array $header): void
    {
        $this->line('Header:');

        foreach ($header as $index => $col) {
            $colLetter = $this->columnLetter($index);
            $display = $col === '' ? '(kosong)' : $col;
            $this->line("  [{$colLetter}] {$display}");
        }

        // Empty header check.
        $emptyHeaders = [];
        foreach ($header as $index => $col) {
            if ($col === '') {
                $emptyHeaders[] = $this->columnLetter($index);
            }
        }

        if ($emptyHeaders !== []) {
            $this->warn(
                '⚠ Header kosong ditemukan pada kolom: '.implode(', ', $emptyHeaders)
            );
        }

        // Duplicate header check.
        $seen = [];
        $duplicates = [];
        foreach ($header as $col) {
            if ($col === '') {
                continue;
            }

            $normalized = mb_strtolower($col);
            if (isset($seen[$normalized])) {
                $duplicates[] = $col;
            }
            $seen[$normalized] = true;
        }

        if ($duplicates !== []) {
            $this->warn(
                '⚠ Header duplikat: '.implode(', ', array_unique($duplicates))
            );
        }
    }

    private function profileSampleRows(array $header, array $dataRows): void
    {
        // Ambil baris non-kosong pertama sebagai sample.
        $samples = [];
        foreach ($dataRows as $row) {
            if (! $this->isEmptyRow($row)) {
                $samples[] = $row;
            }

            if (count($samples) >= self::SAMPLE_LIMIT) {
                break;
            }
        }

        if ($samples === []) {
            $this->line('Tidak ada baris data untuk ditampilkan.');

            return;
        }

        $this->newLine();
        $this->line('Contoh data (maks '.self::SAMPLE_LIMIT.' baris non-kosong):');

        $headerCount = count($header);

        // Truncate panjang nilai agar tabel tetap terbaca.
        $tableHeaders = array_map(
            fn ($col) => mb_strimwidth($col === '' ? '(kosong)' : $col, 0, 25, '…'),
            $header
        );

        $tableRows = [];
        foreach ($samples as $row) {
            $row = array_pad($row, $headerCount, '');
            $row = array_slice($row, 0, $headerCount);

            $tableRows[] = array_map(
                fn ($value) => mb_strimwidth($this->cleanString($value), 0, 35, '…'),
                $row
            );
        }

        $this->table($tableHeaders, $tableRows);
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function cleanString(mixed $value): string
    {
        return trim(
            preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)
        );
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            $cleaned = $this->cleanString($value);

            if ($cleaned !== '') {
                return false;
            }
        }

        return true;
    }
}
