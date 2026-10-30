<?php

namespace App\Console\Commands;

use App\Models\Posko;
use App\Services\Apkuk\SectionPoskoMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Laporan referensi Section → Posko (SectionPoskoMap), baca saja.
 *
 * Tanpa --file: membaca staging section_pic_source_rows yang sedang dipakai generator APKUK dan
 * PASILA. Dengan --file: memvalidasi berkas CSV calon data resmi berformat SECTION_PIC_RAW
 * (PENYULANG, PEMUTUS, POSKO PIC) terhadap master Posko/Penyulang, tanpa mengimport atau menulis
 * apa pun. Format: docs/SECTION_REFERENCE_CONTRACT.md.
 */
class CheckSectionReferences extends Command
{
    public const HEADERS = ['PENYULANG', 'PEMUTUS', 'POSKO PIC'];

    protected $signature = 'pasila:section-references
                            {--file= : Berkas CSV calon referensi (kolom PENYULANG, PEMUTUS, POSKO PIC) untuk divalidasi tanpa diimport}';

    protected $description = 'Laporkan Section terverifikasi per Posko dan referensi yang ditahan (staging aktif atau berkas CSV calon data resmi)';

    public function handle(): int
    {
        $file = $this->option('file');
        if ($file !== null) {
            $rows = $this->readCsv((string) $file);
            if ($rows === null) {
                return self::FAILURE;
            }
            $this->line('Sumber: berkas '.$file.' ('.count($rows).' baris data; tidak diimport)');
            $map = SectionPoskoMap::fromRows($rows);
        } else {
            $this->line('Sumber: staging section_pic_source_rows ('.DB::table('section_pic_source_rows')->count().' baris)');
            $map = SectionPoskoMap::load();
        }

        $entries = $map->entries();
        $verified = array_values(array_filter($entries, fn (array $entry) => $entry['posko'] !== null));
        $held = array_values(array_filter($entries, fn (array $entry) => $entry['posko'] === null));

        $this->table(['Posko', 'Penyulang', 'Section'], array_map(fn (array $entry) => [$entry['posko']->nama_posko, $entry['penyulang'], $entry['section']], $verified));
        if ($held !== []) {
            $this->warn('Referensi ditahan (tidak dipakai untuk penugasan):');
            $this->table(['Penyulang', 'Section', 'POSKO PIC sumber', 'Alasan'], array_map(fn (array $entry) => [
                $entry['penyulang'],
                $entry['section'],
                implode(', ', $entry['posko_pic']) ?: '-',
                $entry['reason'].': '.(SectionPoskoMap::LABELS[$entry['reason']] ?? ''),
            ], $held));
        }

        $covered = array_unique(array_map(fn (array $entry) => $entry['posko']->nama_posko, $verified));
        $without = Posko::query()->where('is_active', true)->orderBy('nama_posko')->pluck('nama_posko')
            ->reject(fn (string $name) => in_array($name, $covered, true))->values()->all();
        if ($without !== []) {
            $this->warn('Posko aktif tanpa Section terverifikasi: '.implode(', ', $without));
        }

        $this->line(sprintf('SECTION_REFERENCES section=%d terverifikasi=%d ditahan=%d posko_tanpa_section=%d',
            count($entries), count($verified), count($held), count($without)));

        return self::SUCCESS;
    }

    /**
     * @return array<int, object{penyulang_raw: ?string, pemutus_raw: ?string, posko_pic_raw: ?string}>|null
     */
    private function readCsv(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Berkas tidak ditemukan atau tidak dapat dibaca: {$path}");

            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($path)));
        $header = null;
        $rows = [];
        $delimiter = ',';
        foreach ($lines as $line) {
            if ($header === null) {
                if (trim(str_replace([',', ';'], '', $line)) === '') {
                    continue; // baris kosong sebelum header, sama seperti importer SECTION_PIC_RAW
                }
                // Aturan header sama dengan importer SECTION_PIC_RAW: tepat tiga kolom bernama persis,
                // kolom tambahan hanya boleh kosong; berkas yang lolos di sini juga lolos sources:import.
                $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
                $header = array_map(fn ($cell) => trim((string) $cell), str_getcsv($line, $delimiter, '"', ''));
                $extra = array_filter(array_slice($header, 3), fn (string $cell) => $cell !== '');
                if (array_slice($header, 0, 3) !== self::HEADERS || $extra !== []) {
                    $this->error('Header tidak sesuai kontrak. Diharapkan tepat kolom A sampai C: '.implode(', ', self::HEADERS).' (huruf kapital, tanpa kolom tambahan); ditemukan: '.implode(', ', array_filter($header, fn (string $cell) => $cell !== '')));

                    return null;
                }

                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"', '');
            $values = array_map(fn ($cell) => ($cell = trim((string) $cell)) === '' ? null : $cell, array_pad(array_slice($cells, 0, 3), 3, null));
            if ($values === [null, null, null]) {
                continue;
            }
            $rows[] = (object) ['penyulang_raw' => $values[0], 'pemutus_raw' => $values[1], 'posko_pic_raw' => $values[2]];
        }

        if ($header === null) {
            $this->error('Berkas kosong: header '.implode(', ', self::HEADERS).' tidak ditemukan.');

            return null;
        }

        return $rows;
    }
}
