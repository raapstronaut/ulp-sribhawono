<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;

/**
 * Pembaca nilai Google Sheets (scope read-only) untuk command sinkronisasi.
 *
 * Dipisahkan dari command agar perilaku sinkronisasi (validasi, exit code,
 * ringkasan log) dapat diuji tanpa jaringan.
 */
class GoogleSheetValues
{
    /**
     * @param  array<string, string>  $params
     * @return array<int, array<int, mixed>>
     */
    public function get(string $credentialsPath, string $spreadsheetId, string $range, array $params = []): array
    {
        $client = new Client;
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);

        return (new Sheets($client))->spreadsheets_values->get($spreadsheetId, $range, $params)->getValues() ?? [];
    }
}
