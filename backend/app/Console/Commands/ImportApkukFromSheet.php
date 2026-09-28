<?php

namespace App\Console\Commands;

use App\Models\ApkukSourceRow;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportApkukFromSheet extends Command
{
    protected $signature = 'apkuk:import-sheet
                            {--replace : Hapus data staging APKUK lama sebelum import}';

    protected $description = 'Import APKUK_RAW dari Google Sheets ke PostgreSQL';

    public function handle(): int
    {
        $credentialsPath = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');
        $range = config('services.google_sheets.range');

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

            $response = $service->spreadsheets_values->get(
                $spreadsheetId,
                $range
            );

            $rows = $response->getValues() ?? [];

            if ($rows === []) {
                throw new RuntimeException('Google Sheet kosong.');
            }

            $header = array_shift($rows);

            $expectedHeader = [
                'ID',
                'Tanggal',
                'Petugas',
                'Penyulang',
                'Section',
                'No. Tiang/Gardu',
                'Naik Dari',
                'Terpasang bh',
                'Kurang bh',
                'Status',
                'Koordinat',
                'Foto',
            ];

            $header = array_map(
                fn ($value) => $this->cleanString($value),
                $header
            );

            if ($header !== $expectedHeader) {
                $this->error('Header APKUK_RAW tidak sesuai.');
                $this->line('Header terbaca:');
                $this->line(implode(' | ', $header));

                return self::FAILURE;
            }

            $now = now();

            $buffer = [];
            $imported = 0;
            $skipped = 0;
            $invalidCoordinates = 0;

            DB::beginTransaction();

            if ($this->option('replace')) {
                ApkukSourceRow::query()->delete();
                $this->info('Data staging APKUK lama dihapus.');
            }

            foreach ($rows as $index => $row) {
                // +2 karena index mulai 0 dan baris 1 adalah header.
                $sourceRowNumber = $index + 2;

                if ($this->isEmptyRow($row)) {
                    $skipped++;
                    continue;
                }

                $row = array_pad($row, 12, null);
                $row = array_slice($row, 0, 12);

                [
                    $sourceId,
                    $tanggal,
                    $petugas,
                    $penyulang,
                    $section,
                    $assetCode,
                    $naikDari,
                    $terpasang,
                    $kurang,
                    $status,
                    $koordinat,
                    $foto,
                ] = $row;

                [$latitude, $longitude, $coordinateValid] =
                    $this->parseCoordinate($koordinat);

                if (
                    $this->nullableString($koordinat) !== null
                    && ! $coordinateValid
                ) {
                    $invalidCoordinates++;
                }

                $buffer[] = [
                    'source_id' => $this->nullableString($sourceId),
                    'tanggal' => $this->parseDate($tanggal, $sourceRowNumber),

                    'petugas_raw' => $this->nullableString($petugas),
                    'penyulang_raw' => $this->nullableString($penyulang),
                    'section_raw' => $this->nullableString($section),
                    'asset_code_raw' => $this->nullableString($assetCode),
                    'naik_dari_raw' => $this->nullableString($naikDari),

                    'terpasang_bh' => $this->nullableInteger($terpasang),
                    'kurang_bh' => $this->nullableInteger($kurang),

                    'status_raw' => $this->nullableString($status),

                    'koordinat_raw' => $this->nullableString($koordinat),
                    'latitude' => $latitude,
                    'longitude' => $longitude,

                    'foto_raw' => $this->nullableString($foto),

                    'source_row_number' => $sourceRowNumber,
                    'imported_at' => $now,

                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) >= 500) {
                    ApkukSourceRow::query()->insert($buffer);

                    $imported += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                ApkukSourceRow::query()->insert($buffer);
                $imported += count($buffer);
            }

            DB::commit();

            $this->newLine();
            $this->info('Import Google Sheets APKUK selesai.');

            $this->table(
                ['Keterangan', 'Jumlah'],
                [
                    ['Baris Sheet setelah header', count($rows)],
                    ['Data berhasil diimport', $imported],
                    ['Baris kosong dilewati', $skipped],
                    ['Koordinat gagal diparsing', $invalidCoordinates],
                    ['Total staging PostgreSQL', ApkukSourceRow::count()],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function parseDate(mixed $value, int $rowNumber): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $formats = [
            'm/d/Y',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                // Coba format berikutnya.
            }
        }

        throw new RuntimeException(
            "Format tanggal tidak dikenali pada baris {$rowNumber}: {$value}"
        );
    }

    private function parseCoordinate(mixed $value): array
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return [null, null, true];
        }

        $parts = array_map('trim', explode(',', $value));

        if (count($parts) !== 2) {
            return [null, null, false];
        }

        if (! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return [null, null, false];
        }

        $latitude = (float) $parts[0];
        $longitude = (float) $parts[1];

        if (
            $latitude < -90 || $latitude > 90 ||
            $longitude < -180 || $longitude > 180
        ) {
            return [null, null, false];
        }

        return [$latitude, $longitude, true];
    }

    private function nullableInteger(mixed $value): ?int
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->cleanString($value);

        return $value === '' ? null : $value;
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
            if ($this->nullableString($value) !== null) {
                return false;
            }
        }

        return true;
    }
}