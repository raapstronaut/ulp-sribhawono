<?php

namespace App\Console\Commands;

use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;

class CheckGangguanGoogle extends Command
{
    protected $signature = 'gangguan:check';

    protected $description =
        'Cek akses dan data gangguan PLN pusat dari Google Sheets';

    public function handle(): int
    {
        $credentialsPath = config(
            'services.google_sheets.credentials_path'
        );

        $spreadsheetId = config(
            'services.google_sheets.spreadsheet_id'
        );

        if (!$credentialsPath || !is_file($credentialsPath)) {
            $this->error('File kredensial Google tidak ditemukan.');
            return self::FAILURE;
        }

        if (!$spreadsheetId) {
            $this->error('Spreadsheet ID belum dikonfigurasi.');
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
                "'GANGGUAN_PUSAT_IMPORT'!A1:CY",
                ['valueRenderOption' => 'FORMATTED_VALUE']
            );

            $rows = $response->getValues() ?? [];

        } catch (\Throwable $e) {
            $this->error('Gagal membaca Google Sheets.');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (count($rows) < 2) {
            $this->error('Header atau data gangguan belum tersedia.');
            return self::FAILURE;
        }

        // Cek posisi kolom penting agar data tidak tertukar.
        $header = $rows[0];

        $expected = [
            0  => 'Penyulang',
            2  => 'ULP Koreksi',
            3  => 'Tanggal',
            25 => 'No: Transaksi APKT',
            51 => 'NoTi',
            79 => 'FOTO CORRECTIVE',
            80 => 'FOTO GANGGUAN',
            91 => 'HEWAN',
            96 => 'ENS APKT',
            98 => 'KEYPOINT TERGANGGU',
        ];

        foreach ($expected as $index => $name) {
            $actual = trim((string) ($header[$index] ?? ''));

            if ($actual !== $name) {
                $this->error(
                    "Header kolom " . ($index + 1) .
                    " tidak sesuai. Diharapkan: {$name}; " .
                    "terbaca: {$actual}"
                );

                return self::FAILURE;
            }
        }

        $dataRows = array_slice($rows, 1);
$barisTanpaId = [];

foreach ($dataRows as $index => $row) {
    $ulp = strtoupper(trim((string) ($row[2] ?? '')));

    if ($ulp !== 'SRIBAWONO') {
        continue;
    }

    $id = rtrim(trim((string) ($row[25] ?? '')), '*');

    if ($id === '') {
        $barisTanpaId[] = [
            $index + 2,
            $row[3] ?? '',
            $row[0] ?? '',
            $row[1] ?? '',
        ];
    }
}

if ($barisTanpaId !== []) {
    $this->warn('DATA GANGGUAN TANPA ID APKT');

    $this->table(
        ['Baris Sheet', 'Tanggal', 'Penyulang', 'Peralatan'],
        $barisTanpaId
    );
}


        $data = array_values(array_filter(
            $dataRows,
            fn ($row) =>
                strtoupper(trim((string) ($row[2] ?? '')))
                === 'SRIBAWONO'
        ));

        $ids = [];

        foreach ($data as $row) {
            $id = rtrim(trim((string) ($row[25] ?? '')), '*');

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $duplicates = count($ids) - count(array_unique($ids));
        $missingIds = count($data) - count($ids);

        $this->info('KONEKSI GOOGLE SHEETS BERHASIL');

        $this->table(
            ['Pemeriksaan', 'Hasil'],
            [
                ['Jumlah data SRIBAWONO', count($data)],
                ['ID transaksi terisi', count($ids)],
                ['ID transaksi kosong', $missingIds],
                ['ID transaksi duplikat', $duplicates],
                ['Header penting', 'Sesuai'],
            ]
        );

        if (count($data) > 0) {
            $first = $data[0];

            $this->newLine();
            $this->info('CONTOH DATA PERTAMA');

            $this->table(
                ['Kolom', 'Nilai'],
                [
                    ['Penyulang', $first[0] ?? ''],
                    ['Peralatan', $first[1] ?? ''],
                    ['ULP', $first[2] ?? ''],
                    ['Tanggal', $first[3] ?? ''],
                    ['ID APKT', $first[25] ?? ''],
                    ['ENS', $first[15] ?? ''],
                ]
            );
        }

        if ($missingIds > 0 || $duplicates > 0) {
            $this->warn(
                'Ada masalah ID transaksi. Jangan impor ke PostgreSQL dulu.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}