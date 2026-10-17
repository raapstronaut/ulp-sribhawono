<?php

namespace App\Console\Commands;

use Google\Client;
use Google\Service\Sheets;
use Illuminate\Console\Command;

class CheckApkukGoogle extends Command
{
    protected $signature = 'apkuk:check';

    protected $description =
        'Periksa data APKUK_RAW dari Google Sheets';

    public function handle(): int
    {
        $path = config('services.google_sheets.credentials_path');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (!$path || !is_file($path) || !$spreadsheetId) {
            $this->error('Konfigurasi Google Sheets belum lengkap.');
            return self::FAILURE;
        }

        try {
            $client = new Client();
            $client->setAuthConfig($path);
            $client->setScopes([
                Sheets::SPREADSHEETS_READONLY,
            ]);

            $service = new Sheets($client);

            $response = $service->spreadsheets_values->get(
                $spreadsheetId,
                "'APKUK_RAW'!A1:L",
                ['valueRenderOption' => 'FORMATTED_VALUE']
            );

            $rows = $response->getValues() ?? [];

        } catch (\Throwable $e) {
            $this->error('Gagal membaca APKUK_RAW.');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (count($rows) < 2) {
            $this->error('APKUK_RAW kosong atau belum lengkap.');
            return self::FAILURE;
        }

        $expected = [
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

        foreach ($expected as $index => $name) {
            $actual = trim((string) ($rows[0][$index] ?? ''));

            if ($actual !== $name) {
                $this->error(
                    "Header kolom " . ($index + 1) .
                    " tidak cocok: {$actual}. Seharusnya: {$name}"
                );
                return self::FAILURE;
            }
        }

        $jumlah = 0;
        $ids = [];
        $duplikat = [];
        $idKosong = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $baris = $index + 2;

            $terisi = array_filter(
                $row,
                fn ($v) => trim((string) $v) !== ''
            );

            if ($terisi === []) {
                continue;
            }

            $jumlah++;
            $id = trim((string) ($row[0] ?? ''));

            if ($id === '') {
                $idKosong[] = $baris;
                continue;
            }

            if (isset($ids[$id])) {
                $duplikat[] = [
                    $id,
                    $ids[$id]['baris'],
                    $baris,
                    $ids[$id]['tiang'],
                    $row[5] ?? '',
                ];
            } else {
                $ids[$id] = [
                    'baris' => $baris,
                    'tiang' => $row[5] ?? '',
                ];
            }
        }

        $this->info('HASIL PEMERIKSAAN APKUK');

        $this->table(
            ['Pemeriksaan', 'Jumlah'],
            [
                ['Data APKUK terbaca', $jumlah],
                ['ID unik', count($ids)],
                ['ID kosong', count($idKosong)],
                ['ID duplikat', count($duplikat)],
                ['Header', 'Sesuai'],
            ]
        );

        if ($duplikat !== []) {
            $this->warn('DITEMUKAN ID DUPLIKAT');

            $this->table(
                [
                    'ID',
                    'Baris pertama',
                    'Baris kedua',
                    'Tiang pertama',
                    'Tiang kedua',
                ],
                $duplikat
            );
        }

        if ($idKosong !== []) {
            $this->warn(
                'ID kosong pada baris: ' .
                implode(', ', array_slice($idKosong, 0, 30))
            );
        }

        $this->info('Pemeriksaan selesai. PostgreSQL tidak diubah.');

        return self::SUCCESS;
    }
}
