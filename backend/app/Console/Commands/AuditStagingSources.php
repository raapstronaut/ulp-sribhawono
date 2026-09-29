<?php

namespace App\Console\Commands;

use App\Models\AssetSourceRow;
use App\Models\VegetasiSourceRow;
use Illuminate\Console\Command;

class AuditStagingSources extends Command
{
    protected $signature = 'sources:audit-staging
                            {--unresolved-limit=20 : Batas contoh nama tiang unresolved yang ditampilkan}';

    protected $description = 'Audit kualitas data staging asset_source_rows, vegetasi_source_rows, dan cross-reference vegetasi -> aset';

    public function handle(): int
    {
        $this->newLine();
        $this->line(str_repeat('═', 70));
        $this->info('  AUDIT DATA SOURCE STAGING (READ-ONLY)');
        $this->line(str_repeat('═', 70));

        $this->auditAssetStaging();
        $this->auditVegetasiStaging();
        $this->auditCrossReference();

        $this->newLine();
        $this->line(str_repeat('═', 70));
        $this->info('  AUDIT SELESAI');
        $this->line(str_repeat('═', 70));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Audit kualitas data tabel asset_source_rows.
     */
    private function auditAssetStaging(): void
    {
        $this->newLine();
        $this->info('📊 1. AUDIT: asset_source_rows (ASSET_RAW)');
        $this->line(str_repeat('─', 70));

        $totalRows = AssetSourceRow::count();

        if ($totalRows === 0) {
            $this->warn('  Tabel asset_source_rows kosong (0 baris).');

            return;
        }

        $idCounts = [];
        $blankIdCount = 0;
        $blankNamaCount = 0;
        $blankCoordCount = 0;
        $invalidLatCount = 0;
        $invalidLongCount = 0;
        $invalidCoordPairCount = 0;

        foreach (AssetSourceRow::query()->select(['id_aset_raw', 'nama_aset_raw', 'latitude_raw', 'longitude_raw'])->lazy(1000) as $row) {
            $id = $this->cleanString($row->id_aset_raw);
            $nama = $this->cleanString($row->nama_aset_raw);
            $lat = $row->latitude_raw;
            $long = $row->longitude_raw;

            if ($id === '') {
                $blankIdCount++;
            } else {
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
            }

            if ($nama === '') {
                $blankNamaCount++;
            }

            $hasLat = $this->cleanString($lat) !== '';
            $hasLong = $this->cleanString($long) !== '';
            $isLatValid = $this->isValidLatitude($lat);
            $isLongValid = $this->isValidLongitude($long);

            if (! $hasLat && ! $hasLong) {
                $blankCoordCount++;
            }
            if ($hasLat && ! $isLatValid) {
                $invalidLatCount++;
            }
            if ($hasLong && ! $isLongValid) {
                $invalidLongCount++;
            }
            if (! $isLatValid || ! $isLongValid) {
                $invalidCoordPairCount++;
            }
        }

        $distinctIds = count($idCounts);
        $duplicateIds = array_filter($idCounts, fn (int $count) => $count > 1);
        $duplicateIdKeyCount = count($duplicateIds);
        $duplicateRowCount = array_sum($duplicateIds);

        $this->table(
            ['Metrik Asset', 'Nilai', 'Keterangan'],
            [
                ['Total baris (rows)', number_format($totalRows), 'OK'],
                ['Distinct ID_ASET', number_format($distinctIds), $distinctIds > 0 ? 'OK' : 'Warning: 0 ID ditemukan'],
                ['Duplicate ID_ASET (distinct keys)', number_format($duplicateIdKeyCount), $duplicateIdKeyCount > 0 ? "Warning: {$duplicateRowCount} baris terdampak" : 'OK (Semua ID unik)'],
                ['Blank ID_ASET', number_format($blankIdCount), $blankIdCount > 0 ? 'Warning: ID kosong' : 'OK (0)'],
                ['Blank NAMA_ASET', number_format($blankNamaCount), $blankNamaCount > 0 ? 'Warning: Nama kosong' : 'OK (0)'],
                ['Koordinat kosong (Lat & Long)', number_format($blankCoordCount), $blankCoordCount > 0 ? 'Info' : 'OK (0)'],
                ['Invalid Latitude (bukan -90..90)', number_format($invalidLatCount), $invalidLatCount > 0 ? 'Warning: Di luar range / non-numerik' : 'OK (0)'],
                ['Invalid Longitude (bukan -180..180)', number_format($invalidLongCount), $invalidLongCount > 0 ? 'Warning: Di luar range / non-numerik' : 'OK (0)'],
                ['Total baris koordinat tidak valid', number_format($invalidCoordPairCount), $invalidCoordPairCount > 0 ? 'Warning' : 'OK (0)'],
            ]
        );
    }

    /**
     * Audit kualitas data tabel vegetasi_source_rows.
     */
    private function auditVegetasiStaging(): void
    {
        $this->newLine();
        $this->info('📊 2. AUDIT: vegetasi_source_rows (VEGETASI_RAW)');
        $this->line(str_repeat('─', 70));

        $totalRows = VegetasiSourceRow::count();

        if ($totalRows === 0) {
            $this->warn('  Tabel vegetasi_source_rows kosong (0 baris).');

            return;
        }

        $idCounts = [];
        $blankIdCount = 0;
        $blankNamaTiangCount = 0;
        $blankCoordCount = 0;
        $invalidLatCount = 0;
        $invalidLongCount = 0;
        $invalidCoordPairCount = 0;

        foreach (VegetasiSourceRow::query()->select(['id_pohon_raw', 'nama_tiang_raw', 'latitude_raw', 'longitude_raw'])->lazy(1000) as $row) {
            $id = $this->cleanString($row->id_pohon_raw);
            $namaTiang = $this->cleanString($row->nama_tiang_raw);
            $lat = $row->latitude_raw;
            $long = $row->longitude_raw;

            if ($id === '') {
                $blankIdCount++;
            } else {
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
            }

            if ($namaTiang === '') {
                $blankNamaTiangCount++;
            }

            $hasLat = $this->cleanString($lat) !== '';
            $hasLong = $this->cleanString($long) !== '';
            $isLatValid = $this->isValidLatitude($lat);
            $isLongValid = $this->isValidLongitude($long);

            if (! $hasLat && ! $hasLong) {
                $blankCoordCount++;
            }
            if ($hasLat && ! $isLatValid) {
                $invalidLatCount++;
            }
            if ($hasLong && ! $isLongValid) {
                $invalidLongCount++;
            }
            if (! $isLatValid || ! $isLongValid) {
                $invalidCoordPairCount++;
            }
        }

        $distinctIds = count($idCounts);
        $duplicateIds = array_filter($idCounts, fn (int $count) => $count > 1);
        $duplicateIdKeyCount = count($duplicateIds);
        $duplicateRowCount = array_sum($duplicateIds);

        $this->table(
            ['Metrik Vegetasi', 'Nilai', 'Keterangan'],
            [
                ['Total baris (rows)', number_format($totalRows), 'OK'],
                ['Distinct ID POHON', number_format($distinctIds), $distinctIds > 0 ? 'OK' : 'Warning: 0 ID ditemukan'],
                ['Duplicate ID POHON (distinct keys)', number_format($duplicateIdKeyCount), $duplicateIdKeyCount > 0 ? "Warning: {$duplicateRowCount} baris terdampak" : 'OK (Semua ID unik)'],
                ['Blank ID POHON', number_format($blankIdCount), $blankIdCount > 0 ? 'Warning: ID kosong' : 'OK (0)'],
                ['Blank NAMA TIANG', number_format($blankNamaTiangCount), $blankNamaTiangCount > 0 ? 'Warning: Nama tiang kosong' : 'OK (0)'],
                ['Koordinat kosong (Lat & Long)', number_format($blankCoordCount), $blankCoordCount > 0 ? 'Info' : 'OK (0)'],
                ['Invalid Latitude (bukan -90..90)', number_format($invalidLatCount), $invalidLatCount > 0 ? 'Warning: Di luar range / non-numerik' : 'OK (0)'],
                ['Invalid Longitude (bukan -180..180)', number_format($invalidLongCount), $invalidLongCount > 0 ? 'Warning: Di luar range / non-numerik' : 'OK (0)'],
                ['Total baris koordinat tidak valid', number_format($invalidCoordPairCount), $invalidCoordPairCount > 0 ? 'Warning' : 'OK (0)'],
            ]
        );
    }

    /**
     * Cross-reference vegetasi -> aset (NAMA TIANG = NAMA_ASET & PENYULANG sama).
     */
    private function auditCrossReference(): void
    {
        $this->newLine();
        $this->info('🔗 3. CROSS-REFERENCE: VEGETASI → ASET');
        $this->line('  Aturan matching: NAMA TIANG = NAMA_ASET dan PENYULANG sama (case-insensitive & trimmed).');
        $this->line(str_repeat('─', 70));

        $totalVegetasi = VegetasiSourceRow::count();
        $totalAsset = AssetSourceRow::count();

        if ($totalVegetasi === 0 || $totalAsset === 0) {
            $this->warn("  Data belum lengkap (Vegetasi: {$totalVegetasi}, Asset: {$totalAsset}). Cross-reference dilewati.");

            return;
        }

        // ─── Build in-memory lookup table dari asset_source_rows ───
        $assetLookup = [];

        foreach (AssetSourceRow::query()->select(['nama_aset_raw', 'penyulang_raw'])->lazy(1000) as $row) {
            $key = $this->normalizeKey($row->penyulang_raw, $row->nama_aset_raw);
            if ($key !== '|') {
                $assetLookup[$key] = true;
            }
        }

        $matchedCount = 0;
        $unmatchedCount = 0;
        $unmatchedDistinctTiang = [];
        $unresolvedPairs = [];

        foreach (VegetasiSourceRow::query()->select(['nama_tiang_raw', 'penyulang_raw'])->lazy(1000) as $row) {
            $namaTiang = $this->cleanString($row->nama_tiang_raw);
            $penyulang = $this->cleanString($row->penyulang_raw);
            $key = $this->normalizeKey($penyulang, $namaTiang);

            if ($namaTiang !== '' && isset($assetLookup[$key])) {
                $matchedCount++;
            } else {
                $unmatchedCount++;

                $tiangDisplay = $namaTiang !== '' ? $namaTiang : '(NAMA TIANG KOSONG)';
                $penyulangDisplay = $penyulang !== '' ? $penyulang : '(PENYULANG KOSONG)';

                $unmatchedDistinctTiang[$tiangDisplay] = true;

                $pairKey = "{$tiangDisplay}___{$penyulangDisplay}";
                if (! isset($unresolvedPairs[$pairKey])) {
                    $unresolvedPairs[$pairKey] = [
                        'nama_tiang' => $tiangDisplay,
                        'penyulang' => $penyulangDisplay,
                        'count' => 0,
                    ];
                }
                $unresolvedPairs[$pairKey]['count']++;
            }
        }

        $distinctUnmatchedTiangCount = count($unmatchedDistinctTiang);
        $matchRate = $totalVegetasi > 0 ? round(($matchedCount / $totalVegetasi) * 100, 2) : 0.0;
        $unmatchRate = $totalVegetasi > 0 ? round(($unmatchedCount / $totalVegetasi) * 100, 2) : 0.0;

        $this->table(
            ['Metrik Cross-Reference', 'Nilai', 'Persentase'],
            [
                ['Vegetasi MATCH (Nama Tiang + Penyulang)', number_format($matchedCount), "{$matchRate}%"],
                ['Vegetasi UNMATCHED / Tidak Match', number_format($unmatchedCount), "{$unmatchRate}%"],
                ['Distinct NAMA TIANG Unmatched', number_format($distinctUnmatchedTiangCount), '-'],
                ['Total Baris Vegetasi Dianalisis', number_format($totalVegetasi), '100%'],
            ]
        );

        // ─── Tampilkan sampel nama tiang unresolved ───
        if ($unresolvedPairs !== []) {
            // Urutkan berdasarkan frekuensi terbanyak
            uasort($unresolvedPairs, fn (array $a, array $b) => $b['count'] <=> $a['count']);

            $limit = max(1, (int) $this->option('unresolved-limit'));
            $samples = array_slice($unresolvedPairs, 0, $limit);

            $this->newLine();
            $this->line("Contoh NAMA TIANG Unresolved (Maks {$limit} teratas berdasarkan frekuensi):");

            $tableRows = [];
            $no = 1;
            foreach ($samples as $sample) {
                $tableRows[] = [
                    $no++,
                    $sample['nama_tiang'],
                    $sample['penyulang'],
                    number_format($sample['count']),
                ];
            }

            $this->table(
                ['No', 'NAMA TIANG (Vegetasi)', 'PENYULANG', 'Jumlah Baris'],
                $tableRows
            );
        }
    }

    private function normalizeKey(?string $penyulang, ?string $nama): string
    {
        return mb_strtoupper($this->cleanString($penyulang)).'|'.mb_strtoupper($this->cleanString($nama));
    }

    private function cleanString(mixed $value): string
    {
        return trim(
            preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)
        );
    }

    private function isValidLatitude(mixed $value): bool
    {
        $cleaned = $this->cleanString($value);
        if ($cleaned === '') {
            return false;
        }

        $normalized = str_replace(',', '.', $cleaned);
        if (! is_numeric($normalized)) {
            return false;
        }

        $float = (float) $normalized;

        return $float >= -90.0 && $float <= 90.0;
    }

    private function isValidLongitude(mixed $value): bool
    {
        $cleaned = $this->cleanString($value);
        if ($cleaned === '') {
            return false;
        }

        $normalized = str_replace(',', '.', $cleaned);
        if (! is_numeric($normalized)) {
            return false;
        }

        $float = (float) $normalized;

        return $float >= -180.0 && $float <= 180.0;
    }
}
