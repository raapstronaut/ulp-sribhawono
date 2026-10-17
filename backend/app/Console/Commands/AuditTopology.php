<?php

namespace App\Console\Commands;

use App\Models\AssetSourceRow;
use App\Models\SectionPicSourceRow;
use Illuminate\Console\Command;

class AuditTopology extends Command
{
    protected $signature = 'topology:audit
                            {--limit=20 : Batas contoh item per kategori yang ditampilkan}';

    protected $description = 'Audit read-only topologi jaringan: SECTION PIC (derived fill-down), KEYPOINT, feeder coverage, name & type matching, conflict, review, dan proximity ke aset';

    /**
     * Posko PIC yang diketahui/diharapkan.
     */
    public const KNOWN_POSKO = [
        'SRIBHAWONO',
        'PUGUNG',
        'JABUNG',
        'PASIR SAKTI',
        'WAY JEPARA',
        'TRIDATU',
    ];

    public function handle(): int
    {
        $this->writeHeader();

        $limit = max(1, (int) $this->option('limit'));

        // ─── Section A: SECTION PIC SUMMARY ───
        $sectionPicData = $this->auditSectionPic($limit);

        // ─── Section B: KEYPOINT SUMMARY ───
        $keypointData = $this->auditKeypoint($limit);

        // ─── Section C: FEEDER COVERAGE ───
        $this->auditFeederCoverage($sectionPicData, $keypointData);

        // ─── Section D: NAME & TYPE MATCHING ───
        $matchingData = $this->auditNameMatching($sectionPicData, $keypointData, $limit);

        // ─── Section E: CONFLICT & REVIEW ITEMS ───
        $proximityData = $this->auditKeypointAssetProximity($keypointData, $limit);
        $this->auditReviewItems($sectionPicData, $keypointData, $matchingData, $proximityData, $limit);

        $this->writeFooter();

        return self::SUCCESS;
    }

    private function writeHeader(): void
    {
        if ($this->output === null) {
            return;
        }

        $this->newLine();
        $this->line(str_repeat('═', 78));
        $this->info('  TOPOLOGY AUDIT & ANALYSIS (READ-ONLY)');
        $this->line(str_repeat('═', 78));
    }

    private function writeFooter(): void
    {
        if ($this->output === null) {
            return;
        }

        $this->newLine();
        $this->line(str_repeat('═', 78));
        $this->info('  TOPOLOGY AUDIT SELESAI (DATABASE UTUH / TIDAK BERUBAH)');
        $this->line(str_repeat('═', 78));
        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION A: SECTION PIC SUMMARY (Derived Fill-Down)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     total_rows: int,
     *     derived_rows: list<array{
     *         id: int,
     *         source_row_number: ?int,
     *         raw_feeder: ?string,
     *         derived_feeder: ?string,
     *         canonical_feeder: string,
     *         raw_pemutus: ?string,
     *         canonical_name: string,
     *         derived_type: ?string,
     *         posko: string,
     *         is_posko_known: bool,
     *         is_posko_blank: bool
     *     }>,
     *     distinct_feeders: list<string>,
     *     distinct_posko: list<string>,
     *     blank_posko_rows: list<array{row_number: ?int, feeder: ?string, raw_pemutus: ?string}>,
     *     unresolved_rows: list<array{row_number: ?int, raw_pemutus: ?string, posko: string}>
     * }
     */
    public function auditSectionPic(int $limit = 20): array
    {
        if ($this->output !== null) {
            $this->newLine();
            $this->info('📋 A. SECTION PIC SUMMARY (Derived Fill-Down In-Memory)');
            $this->line(str_repeat('─', 78));
        }

        $rawRows = SectionPicSourceRow::query()
            ->orderBy('source_row_number', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $totalRows = $rawRows->count();

        if ($totalRows === 0) {
            if ($this->output !== null) {
                $this->warn('  Tabel section_pic_source_rows kosong (0 baris).');
            }

            return [
                'total_rows' => 0,
                'derived_rows' => [],
                'distinct_feeders' => [],
                'distinct_posko' => [],
                'blank_posko_rows' => [],
                'unresolved_rows' => [],
            ];
        }

        $currentFeeder = null;
        $derivedRows = [];
        $unresolvedRows = [];
        $blankPoskoRows = [];
        $feederCounts = [];
        $poskoCounts = [];
        $feederPoskoPairs = [];

        foreach ($rawRows as $row) {
            $rawFeeder = $this->cleanString($row->penyulang_raw);
            if ($rawFeeder !== '') {
                $currentFeeder = $rawFeeder;
            }

            $derivedFeeder = $currentFeeder;
            $posko = $this->cleanString($row->posko_pic_raw);
            $pemutus = $this->cleanString($row->pemutus_raw);
            $canonicalFeeder = $derivedFeeder !== null ? $this->canonicalFeeder($derivedFeeder) : '';
            $canonicalName = $this->normalizeEquipmentName($pemutus);
            $derivedType = $this->deriveEquipmentType($pemutus);
            $isPoskoBlank = ($posko === '');
            $isPoskoKnown = ! $isPoskoBlank && in_array(mb_strtoupper($posko), self::KNOWN_POSKO, true);

            if ($derivedFeeder === null || $derivedFeeder === '') {
                $unresolvedRows[] = [
                    'row_number' => $row->source_row_number,
                    'raw_pemutus' => $pemutus,
                    'posko' => $posko,
                ];
            } else {
                $feederCounts[$derivedFeeder] = ($feederCounts[$derivedFeeder] ?? 0) + 1;
                $pairKey = "{$derivedFeeder} → ".($posko !== '' ? $posko : '(BLANK POSKO)');
                $feederPoskoPairs[$pairKey] = ($feederPoskoPairs[$pairKey] ?? 0) + 1;
            }

            if ($isPoskoBlank) {
                $blankPoskoRows[] = [
                    'row_number' => $row->source_row_number,
                    'feeder' => $derivedFeeder,
                    'raw_pemutus' => $pemutus,
                ];
            } else {
                $poskoCounts[$posko] = ($poskoCounts[$posko] ?? 0) + 1;
            }

            $derivedRows[] = [
                'id' => $row->id,
                'source_row_number' => $row->source_row_number,
                'raw_feeder' => $row->penyulang_raw,
                'derived_feeder' => $derivedFeeder,
                'canonical_feeder' => $canonicalFeeder,
                'raw_pemutus' => $row->pemutus_raw,
                'canonical_name' => $canonicalName,
                'derived_type' => $derivedType,
                'posko' => $posko,
                'is_posko_known' => $isPoskoKnown,
                'is_posko_blank' => $isPoskoBlank,
            ];
        }

        $distinctFeeders = array_keys($feederCounts);
        $distinctPosko = array_keys($poskoCounts);

        if ($this->output !== null) {
            $this->table(
                ['Metrik Section PIC', 'Nilai', 'Keterangan'],
                [
                    ['Total baris (rows)', number_format($totalRows), 'OK'],
                    ['Distinct Feeder (setelah fill-down)', number_format(count($distinctFeeders)), count($distinctFeeders).' penyulang teridentifikasi'],
                    ['Distinct Posko PIC', number_format(count($distinctPosko)), implode(', ', $distinctPosko)],
                    ['Blank POSKO PIC', number_format(count($blankPoskoRows)), count($blankPoskoRows) > 0 ? '⚠ Masuk REVIEW (Posko kosong)' : 'OK (0)'],
                    ['Baris gagal dapat feeder (unresolved fill-down)', number_format(count($unresolvedRows)), count($unresolvedRows) === 0 ? 'OK (0)' : '⚠ Warning: Baris awal NULL sebelum ada feeder'],
                ]
            );

            // Count per Feeder
            $this->newLine();
            $this->line('Breakdown Baris per Feeder:');
            $feederTable = [];
            foreach ($feederCounts as $fdr => $cnt) {
                $feederTable[] = [$fdr, $this->canonicalFeeder($fdr), number_format($cnt)];
            }
            $this->table(['Feeder (Derived)', 'Canonical Feeder', 'Jumlah Baris'], $feederTable);

            // Count per Posko
            $this->newLine();
            $this->line('Breakdown Baris per Posko PIC:');
            $poskoTable = [];
            foreach ($poskoCounts as $psk => $cnt) {
                $status = in_array(mb_strtoupper($psk), self::KNOWN_POSKO, true) ? 'Known Posko (OK)' : 'UNKNOWN POSKO';
                $poskoTable[] = [$psk, number_format($cnt), $status];
            }
            if ($blankPoskoRows !== []) {
                $poskoTable[] = ['(BLANK POSKO PIC)', number_format(count($blankPoskoRows)), '⚠ PERLU REVIEW'];
            }
            $this->table(['Posko PIC', 'Jumlah Baris', 'Status Validasi'], $poskoTable);

            // Pasangan Feeder ↔ Posko
            $this->newLine();
            $this->line('Mapping Feeder ↔ Posko PIC:');
            $pairTable = [];
            foreach ($feederPoskoPairs as $pair => $cnt) {
                [$fdr, $psk] = explode(' → ', $pair);
                $pairTable[] = [$fdr, $psk, number_format($cnt)];
            }
            $this->table(['Feeder', 'Posko PIC', 'Jumlah Baris'], $pairTable);

            // Blank Posko PIC Table
            if ($blankPoskoRows !== []) {
                $this->newLine();
                $this->warn('⚠ Daftar Baris dengan Blank POSKO PIC (Masuk REVIEW):');
                $blankTable = [];
                foreach ($blankPoskoRows as $bp) {
                    $blankTable[] = [$bp['row_number'] ?? '-', $bp['feeder'] ?? '(NULL)', $bp['raw_pemutus'] ?? '-'];
                }
                $this->table(['Source Row', 'Feeder (Derived)', 'Pemutus (Raw)'], $blankTable);
            }

            if ($unresolvedRows !== []) {
                $this->newLine();
                $this->warn('⚠ Baris yang tidak mendapatkan feeder dari fill-down:');
                $unresolvedTable = [];
                foreach ($unresolvedRows as $unres) {
                    $unresolvedTable[] = [$unres['row_number'] ?? '-', $unres['raw_pemutus'] ?? '-', $unres['posko']];
                }
                $this->table(['Source Row', 'Pemutus', 'Posko PIC'], $unresolvedTable);
            }
        }

        return [
            'total_rows' => $totalRows,
            'derived_rows' => $derivedRows,
            'distinct_feeders' => $distinctFeeders,
            'distinct_posko' => $distinctPosko,
            'blank_posko_rows' => $blankPoskoRows,
            'unresolved_rows' => $unresolvedRows,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION B: KEYPOINT SUMMARY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     total_rows: int,
     *     keypoint_rows: list<array{
     *         id: int,
     *         source_row_number: ?int,
     *         raw_feeder: ?string,
     *         canonical_feeder: string,
     *         jenis: string,
     *         normalized_jenis: ?string,
     *         raw_name: ?string,
     *         canonical_name: string,
     *         raw_coord: ?string,
     *         parsed_coord: ?array{lat: float, lon: float, raw_lat: string, raw_lon: string},
     *         note: ?string
     *     }>,
     *     distinct_feeders: list<string>,
     *     distinct_jenis: list<string>,
     *     blank_name_count: int,
     *     blank_coord_count: int,
     *     invalid_coord_count: int,
     *     duplicate_rows: list<array{row_number: ?int, feeder: ?string, nama: ?string, jenis: ?string, koordinat: ?string}>
     * }
     */
    public function auditKeypoint(int $limit = 20): array
    {
        if ($this->output !== null) {
            $this->newLine();
            $this->info('📍 B. KEYPOINT SUMMARY');
            $this->line(str_repeat('─', 78));
        }
        $rawRows = \Illuminate\Support\Facades\DB::table('keypoints')
            ->where('sumber_input', 'SPS_BOS')
            ->select([
                'id',
                'source_row_number',
                'penyulang as penyulang_raw',
                'jenis as jenis_raw',
                'nama as nama_raw',
                'koordinat_raw',
                'note as note_raw',
            ])
            ->orderBy('source_row_number', 'asc')
            ->orderBy('id', 'asc')
            ->get();


        $totalRows = $rawRows->count();

        if ($totalRows === 0) {
            if ($this->output !== null) {
                $this->warn('  Tabel keypoints kosong (0 baris).');
            }

            return [
                'total_rows' => 0,
                'keypoint_rows' => [],
                'distinct_feeders' => [],
                'distinct_jenis' => [],
                'blank_name_count' => 0,
                'blank_coord_count' => 0,
                'invalid_coord_count' => 0,
                'duplicate_rows' => [],
            ];
        }

        $keypointRows = [];
        $feederCounts = [];
        $jenisCounts = [];
        $nameFeederGroups = [];
        $blankNameCount = 0;
        $blankCoordCount = 0;
        $invalidCoordCount = 0;

        foreach ($rawRows as $row) {
            $feeder = $this->cleanString($row->penyulang_raw);
            $jenis = $this->cleanString($row->jenis_raw);
            $name = $this->cleanString($row->nama_raw);
            $coordRaw = $this->cleanString($row->koordinat_raw);
            $note = $this->cleanString($row->note_raw);

            if ($feeder !== '') {
                $feederCounts[$feeder] = ($feederCounts[$feeder] ?? 0) + 1;
            }

            if ($jenis !== '') {
                $jenisCounts[$jenis] = ($jenisCounts[$jenis] ?? 0) + 1;
            }

            if ($name === '') {
                $blankNameCount++;
            }

            if ($coordRaw === '') {
                $blankCoordCount++;
                $parsedCoord = null;
            } else {
                $parsedCoord = $this->parseCoordinateString($coordRaw);
                if ($parsedCoord === null) {
                    $invalidCoordCount++;
                }
            }

            $kpItem = [
                'id' => $row->id,
                'source_row_number' => $row->source_row_number,
                'raw_feeder' => $row->penyulang_raw,
                'canonical_feeder' => $this->canonicalFeeder($feeder),
                'jenis' => $jenis,
                'normalized_jenis' => $this->normalizeKeypointType($jenis),
                'raw_name' => $row->nama_raw,
                'canonical_name' => $this->normalizeEquipmentName($name),
                'raw_coord' => $row->koordinat_raw,
                'parsed_coord' => $parsedCoord,
                'note' => $note !== '' ? $note : null,
            ];

            if ($name !== '' && $feeder !== '') {
                $pairKey = mb_strtoupper($feeder).' | '.mb_strtoupper($name);
                $nameFeederGroups[$pairKey][] = $kpItem;
            }

            $keypointRows[] = $kpItem;
        }

        $duplicateRows = [];
        foreach ($nameFeederGroups as $group) {
            if (count($group) > 1) {
                foreach ($group as $item) {
                    $duplicateRows[] = [
                        'row_number' => $item['source_row_number'],
                        'feeder' => $item['raw_feeder'],
                        'nama' => $item['raw_name'],
                        'jenis' => $item['jenis'],
                        'koordinat' => $item['raw_coord'],
                    ];
                }
            }
        }

        if ($this->output !== null) {
            $this->table(
                ['Metrik Keypoint', 'Nilai', 'Keterangan'],
                [
                    ['Total baris (rows)', number_format($totalRows), 'OK'],
                    ['Distinct Feeder', number_format(count($feederCounts)), implode(', ', array_keys($feederCounts))],
                    ['Distinct Jenis', number_format(count($jenisCounts)), implode(', ', array_keys($jenisCounts))],
                    ['Blank NAMA', number_format($blankNameCount), $blankNameCount > 0 ? 'Warning' : 'OK (0)'],
                    ['Blank KOORDINAT', number_format($blankCoordCount), $blankCoordCount > 0 ? 'Info' : 'OK (0)'],
                    ['Invalid Koordinat Strings', number_format($invalidCoordCount), $invalidCoordCount > 0 ? 'Warning: format/range salah' : 'OK (0)'],
                    ['Duplicate Raw NAMA + PENYULANG', number_format(count($duplicateRows)), count($duplicateRows) > 0 ? '⚠ Warning: '.count($duplicateRows).' baris duplicate (masuk REVIEW)' : 'OK (Semua unik)'],
                ]
            );

            // Breakdown Feeder
            $this->newLine();
            $this->line('Breakdown Baris per Feeder:');
            $feederTable = [];
            foreach ($feederCounts as $fdr => $cnt) {
                $feederTable[] = [$fdr, $this->canonicalFeeder($fdr), number_format($cnt)];
            }
            $this->table(['Feeder', 'Canonical Feeder', 'Jumlah Baris'], $feederTable);

            // Breakdown Jenis
            $this->newLine();
            $this->line('Breakdown Baris per Jenis:');
            $jenisTable = [];
            foreach ($jenisCounts as $jns => $cnt) {
                $jenisTable[] = [$jns, number_format($cnt)];
            }
            $this->table(['Jenis Keypoint', 'Jumlah Baris'], $jenisTable);

            // Daftar Duplicate Keypoint Lengkap
            if ($duplicateRows !== []) {
                $this->newLine();
                $this->warn('⚠ Daftar Lengkap Duplicate Raw NAMA + PENYULANG pada Keypoint:');
                $dupTable = [];
                foreach ($duplicateRows as $dup) {
                    $dupTable[] = [
                        $dup['row_number'] ?? '-',
                        $dup['feeder'] ?? '-',
                        $dup['nama'] ?? '-',
                        $dup['jenis'] ?? '-',
                        $dup['koordinat'] ?? '-',
                    ];
                }
                $this->table(['Source Row', 'Feeder', 'Nama Keypoint', 'Jenis', 'Koordinat'], $dupTable);
            }
        }

        return [
            'total_rows' => $totalRows,
            'keypoint_rows' => $keypointRows,
            'distinct_feeders' => array_keys($feederCounts),
            'distinct_jenis' => array_keys($jenisCounts),
            'blank_name_count' => $blankNameCount,
            'blank_coord_count' => $blankCoordCount,
            'invalid_coord_count' => $invalidCoordCount,
            'duplicate_rows' => $duplicateRows,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION C: FEEDER COVERAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function auditFeederCoverage(array $sectionPicData, array $keypointData): array
    {
        if ($this->output !== null) {
            $this->newLine();
            $this->info('🌐 C. FEEDER COVERAGE (SECTION PIC vs KEYPOINT)');
            $this->line('  Canonical Feeder Alias: TANGGAMUS → TANGGAMUS 2');
            $this->line(str_repeat('─', 78));
        }

        $secFeeders = [];
        foreach ($sectionPicData['derived_rows'] as $row) {
            if ($row['canonical_feeder'] !== '') {
                $secFeeders[$row['canonical_feeder']] = true;
            }
        }

        $kpFeeders = [];
        foreach ($keypointData['keypoint_rows'] as $row) {
            if ($row['canonical_feeder'] !== '') {
                $kpFeeders[$row['canonical_feeder']] = true;
            }
        }

        $secList = array_keys($secFeeders);
        $kpList = array_keys($kpFeeders);

        $intersection = array_values(array_intersect($secList, $kpList));
        $secOnly = array_values(array_diff($secList, $kpList));
        $kpOnly = array_values(array_diff($kpList, $secList));

        sort($intersection);
        sort($secOnly);
        sort($kpOnly);

        if ($this->output !== null) {
            $this->table(
                ['Kategori Coverage', 'Jumlah Feeder', 'Daftar Feeder Canonical'],
                [
                    ['Ada di KEDUANYA (Intersection)', count($intersection), implode(', ', $intersection)],
                    ['HANYA di SECTION PIC', count($secOnly), count($secOnly) > 0 ? implode(', ', $secOnly) : '(none)'],
                    ['HANYA di KEYPOINT', count($kpOnly), count($kpOnly) > 0 ? implode(', ', $kpOnly) : '(none)'],
                    ['Total Feeder Unik Gabungan', count(array_unique(array_merge($secList, $kpList))), '-'],
                ]
            );
        }

        return [
            'intersection' => $intersection,
            'section_pic_only' => $secOnly,
            'keypoint_only' => $kpOnly,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION D: NAME & TYPE MATCHING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     matched: list<array{
     *         canonical_feeder: string,
     *         canonical_name: string,
     *         sec_raw_name: ?string,
     *         sec_row_number: ?int,
     *         kp_raw_name: ?string,
     *         kp_row_number: ?int,
     *         kp_jenis: string,
     *         sec_posko: string
     *     }>,
     *     raw_matched_count: int,
     *     unique_entity_matched_count: int,
     *     unique_matched_entities: list<string>,
     *     type_mismatches: list<array{
     *         canonical_feeder: string,
     *         canonical_name: string,
     *         sec_raw_name: ?string,
     *         sec_expected_type: ?string,
     *         sec_row_number: ?int,
     *         kp_raw_name: ?string,
     *         kp_actual_type: ?string,
     *         kp_row_number: ?int,
     *         sec_posko: string,
     *         reason: string
     *     }>,
     *     conflicts: list<array{
     *         canonical_name: string,
     *         sec_feeder: string,
     *         sec_raw_name: ?string,
     *         sec_posko: string,
     *         kp_feeders: string,
     *         kp_raw_names: string,
     *         reason: string
     *     }>,
     *     section_pic_only: list<array{source: string, feeder: ?string, raw_name: ?string, canonical_name: string, posko: string}>,
     *     keypoint_only: list<array{source: string, feeder: ?string, raw_name: ?string, canonical_name: string, jenis: string}>
     * }
     */
    public function auditNameMatching(array $sectionPicData, array $keypointData, int $limit = 20): array
    {
        if ($this->output !== null) {
            $this->newLine();
            $this->info('🔍 D. NAME & TYPE MATCHING (SECTION PIC vs KEYPOINT)');
            $this->line('  Aturan matching: canonical feeder sama, canonical name sama, dan type kompatibel.');
            $this->line('  Jika feeder & nama sama tetapi TYPE BEDA → TYPE_MISMATCH (jangan MATCHED).');
            $this->line('  Jika nama sama tetapi FEEDER BEDA → CONFLICT.');
            $this->line(str_repeat('─', 78));
        }

        $secRows = $sectionPicData['derived_rows'];
        $kpRows = $keypointData['keypoint_rows'];

        // Index Keypoint by canonical_name and by (canonical_feeder | canonical_name)
        $kpByName = [];
        $kpByFeederAndName = [];

        foreach ($kpRows as $kp) {
            $name = $kp['canonical_name'];
            $feeder = $kp['canonical_feeder'];
            if ($name === '') {
                continue;
            }

            $kpByName[$name][] = $kp;
            $kpByFeederAndName["{$feeder}|{$name}"][] = $kp;
        }

        // Index Section PIC by canonical_name and by (canonical_feeder | canonical_name)
        $secByName = [];
        $secByFeederAndName = [];

        foreach ($secRows as $sec) {
            $name = $sec['canonical_name'];
            $feeder = $sec['canonical_feeder'];
            if ($name === '') {
                continue;
            }

            $secByName[$name][] = $sec;
            $secByFeederAndName["{$feeder}|{$name}"][] = $sec;
        }

        $matched = [];
        $uniqueMatchedEntities = [];
        $typeMismatches = [];
        $sectionPicOnly = [];
        $conflicts = [];

        // Check Section PIC records
        foreach ($secRows as $sec) {
            $name = $sec['canonical_name'];
            $feeder = $sec['canonical_feeder'];
            $secExpectedType = $sec['derived_type'];

            if ($name === '') {
                $sectionPicOnly[] = [
                    'source' => 'SECTION_PIC',
                    'feeder' => $feeder,
                    'raw_name' => $sec['raw_pemutus'],
                    'canonical_name' => '(BLANK NAME)',
                    'posko' => $sec['posko'],
                ];

                continue;
            }

            $hasExactFeederAndName = isset($kpByFeederAndName["{$feeder}|{$name}"]);

            if ($hasExactFeederAndName) {
                $matchingKps = $kpByFeederAndName["{$feeder}|{$name}"];

                foreach ($matchingKps as $mkp) {
                    $kpActualType = $mkp['normalized_jenis'];

                    // Type validation: If Section PIC has explicit expected type and Keypoint has explicit type,
                    // and they differ -> TYPE_MISMATCH
                    if ($secExpectedType !== null && $kpActualType !== null && $secExpectedType !== $kpActualType) {
                        $typeMismatches[] = [
                            'canonical_feeder' => $feeder,
                            'canonical_name' => $name,
                            'sec_raw_name' => $sec['raw_pemutus'],
                            'sec_expected_type' => $secExpectedType,
                            'sec_row_number' => $sec['source_row_number'],
                            'kp_raw_name' => $mkp['raw_name'],
                            'kp_actual_type' => $mkp['jenis'],
                            'kp_row_number' => $mkp['source_row_number'],
                            'sec_posko' => $sec['posko'],
                            'reason' => "Tipe berbeda: Section PIC ({$secExpectedType}) vs Keypoint ({$kpActualType})",
                        ];
                    } else {
                        // MATCHED!
                        $entityKey = "{$feeder}|{$name}";
                        $uniqueMatchedEntities[$entityKey] = true;

                        $matched[] = [
                            'canonical_feeder' => $feeder,
                            'canonical_name' => $name,
                            'sec_raw_name' => $sec['raw_pemutus'],
                            'sec_row_number' => $sec['source_row_number'],
                            'kp_raw_name' => $mkp['raw_name'],
                            'kp_row_number' => $mkp['source_row_number'],
                            'kp_jenis' => $mkp['jenis'],
                            'sec_posko' => $sec['posko'],
                        ];
                    }
                }
            } else {
                // Not matched on same feeder. Check if name exists in Keypoint on a DIFFERENT feeder!
                if (isset($kpByName[$name])) {
                    $otherKps = $kpByName[$name];
                    $otherFeeders = array_unique(array_column($otherKps, 'canonical_feeder'));

                    $conflicts[] = [
                        'canonical_name' => $name,
                        'sec_feeder' => $feeder,
                        'sec_raw_name' => $sec['raw_pemutus'],
                        'sec_posko' => $sec['posko'],
                        'kp_feeders' => implode(', ', $otherFeeders),
                        'kp_raw_names' => implode(', ', array_unique(array_column($otherKps, 'raw_name'))),
                        'reason' => "Feeder berbeda: Section PIC ({$feeder}) vs Keypoint (".implode(', ', $otherFeeders).')',
                    ];
                } else {
                    // Only exists in Section PIC
                    $sectionPicOnly[] = [
                        'source' => 'SECTION_PIC',
                        'feeder' => $feeder,
                        'raw_name' => $sec['raw_pemutus'],
                        'canonical_name' => $name,
                        'posko' => $sec['posko'],
                    ];
                }
            }
        }

        // Check Keypoint-only records
        $keypointOnly = [];
        foreach ($kpRows as $kp) {
            $name = $kp['canonical_name'];
            $feeder = $kp['canonical_feeder'];

            if ($name === '') {
                $keypointOnly[] = [
                    'source' => 'KEYPOINT',
                    'feeder' => $feeder,
                    'raw_name' => $kp['raw_name'],
                    'canonical_name' => '(BLANK NAME)',
                    'jenis' => $kp['jenis'],
                ];

                continue;
            }

            $hasExactMatch = isset($secByFeederAndName["{$feeder}|{$name}"]);
            $hasNameInSec = isset($secByName[$name]);

            if (! $hasExactMatch && ! $hasNameInSec) {
                $keypointOnly[] = [
                    'source' => 'KEYPOINT',
                    'feeder' => $feeder,
                    'raw_name' => $kp['raw_name'],
                    'canonical_name' => $name,
                    'jenis' => $kp['jenis'],
                ];
            }
        }

        $rawMatchedCount = count($matched);
        $uniqueEntityMatchedCount = count($uniqueMatchedEntities);

        if ($this->output !== null) {
            $this->table(
                ['Kategori Klasifikasi', 'Jumlah', 'Keterangan'],
                [
                    ['Unique Canonical Entities MATCHED', number_format($uniqueEntityMatchedCount), 'Entitas unik (feeder + canonical name + type cocok)'],
                    ['Raw Rows MATCHED (Section PIC matches)', number_format($rawMatchedCount), 'Total relasi baris mentah yang cocok'],
                    ['TYPE_MISMATCH (Feeder & Nama Cocok, Tipe Beda)', number_format(count($typeMismatches)), count($typeMismatches) > 0 ? '⚠ Masuk REVIEW (contoh: LBSM vs FCO)' : 'OK (0)'],
                    ['CONFLICT (Nama Sama, Feeder Beda)', number_format(count($conflicts)), count($conflicts) > 0 ? '⚠ Masuk REVIEW (contoh: LBSM ZAPTOR dll)' : 'OK (0)'],
                    ['SECTION_PIC_ONLY', number_format(count($sectionPicOnly)), 'Ada di Section PIC, tidak ditemukan di Keypoint'],
                    ['KEYPOINT_ONLY', number_format(count($keypointOnly)), 'Ada di Keypoint, tidak ditemukan di Section PIC'],
                ]
            );

            // ─── Table MATCHED (Sample) ───
            if ($matched !== []) {
                $this->newLine();
                $this->line("Contoh Item MATCHED (Maks {$limit} teratas):");
                $matchSample = array_slice($matched, 0, $limit);
                $matchTable = [];
                $no = 1;
                foreach ($matchSample as $m) {
                    $matchTable[] = [
                        $no++,
                        $m['canonical_feeder'],
                        $m['canonical_name'],
                        $m['sec_raw_name'],
                        $m['kp_raw_name'],
                        $m['kp_jenis'],
                        $m['sec_posko'],
                    ];
                }
                $this->table(
                    ['No', 'Canonical Feeder', 'Canonical Name', 'Section PIC (Raw)', 'Keypoint (Raw)', 'Jenis', 'Posko PIC'],
                    $matchTable
                );
            }

            // ─── Table SECTION_PIC_ONLY (Sample) ───
            if ($sectionPicOnly !== []) {
                $this->newLine();
                $this->line("Contoh Item SECTION_PIC_ONLY (Maks {$limit} teratas):");
                $secSample = array_slice($sectionPicOnly, 0, $limit);
                $secTable = [];
                $no = 1;
                foreach ($secSample as $s) {
                    $secTable[] = [
                        $no++,
                        $s['feeder'] ?? '-',
                        $s['raw_name'] ?? '-',
                        $s['canonical_name'],
                        $s['posko'],
                    ];
                }
                $this->table(
                    ['No', 'Feeder', 'Raw Name', 'Canonical Name', 'Posko PIC'],
                    $secTable
                );
            }

            // ─── Table KEYPOINT_ONLY (Sample) ───
            if ($keypointOnly !== []) {
                $this->newLine();
                $this->line("Contoh Item KEYPOINT_ONLY (Maks {$limit} teratas):");
                $kpSample = array_slice($keypointOnly, 0, $limit);
                $kpTable = [];
                $no = 1;
                foreach ($kpSample as $k) {
                    $kpTable[] = [
                        $no++,
                        $k['feeder'] ?? '-',
                        $k['raw_name'] ?? '-',
                        $k['canonical_name'],
                        $k['jenis'],
                    ];
                }
                $this->table(
                    ['No', 'Feeder', 'Raw Name', 'Canonical Name', 'Jenis'],
                    $kpTable
                );
            }
        }

        return [
            'matched' => $matched,
            'raw_matched_count' => $rawMatchedCount,
            'unique_entity_matched_count' => $uniqueEntityMatchedCount,
            'unique_matched_entities' => array_keys($uniqueMatchedEntities),
            'type_mismatches' => $typeMismatches,
            'conflicts' => $conflicts,
            'section_pic_only' => $sectionPicOnly,
            'keypoint_only' => $keypointOnly,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION E: CONFLICT & REVIEW ITEMS SUMMARY
    // ─────────────────────────────────────────────────────────────────────────

    public function auditReviewItems(
        array $sectionPicData,
        array $keypointData,
        array $matchingData,
        array $proximityData,
        int $limit = 20
    ): array {
        if ($this->output === null) {
            return [];
        }

        $this->newLine();
        $this->info('⚠️  E. CONFLICT & REVIEW ITEMS (MEMERLUKAN TINDAK LANJUT / VERIFIKASI)');
        $this->line(str_repeat('─', 78));

        // 1. Conflicts (Feeder berbeda)
        $conflicts = $matchingData['conflicts'];
        if ($conflicts !== []) {
            $this->newLine();
            $this->error('1. CONFLICT: Canonical Name Sama, Feeder Berbeda:');
            $conflictTable = [];
            $no = 1;
            foreach (array_slice($conflicts, 0, $limit) as $c) {
                $conflictTable[] = [
                    $no++,
                    $c['canonical_name'],
                    $c['sec_feeder'],
                    $c['sec_raw_name'],
                    $c['kp_feeders'],
                    $c['kp_raw_names'],
                    $c['sec_posko'],
                ];
            }
            $this->table(
                ['No', 'Canonical Name', 'Feeder Section PIC', 'Raw Section PIC', 'Feeder Keypoint', 'Raw Keypoint', 'Posko PIC'],
                $conflictTable
            );
        } else {
            $this->line('  1. CONFLICT (Feeder Berbeda): 0 item (OK)');
        }

        // 2. Type Mismatches
        $typeMismatches = $matchingData['type_mismatches'];
        if ($typeMismatches !== []) {
            $this->newLine();
            $this->error('2. TYPE MISMATCH: Feeder & Nama Sama, Jenis/Tipe Berbeda:');
            $typeTable = [];
            $no = 1;
            foreach (array_slice($typeMismatches, 0, $limit) as $t) {
                $typeTable[] = [
                    $no++,
                    $t['canonical_feeder'],
                    $t['canonical_name'],
                    $t['sec_raw_name']." (Expected: {$t['sec_expected_type']})",
                    $t['kp_raw_name']." (Actual: {$t['kp_actual_type']})",
                    $t['sec_posko'],
                ];
            }
            $this->table(
                ['No', 'Feeder', 'Canonical Name', 'Section PIC (Expected)', 'Keypoint (Actual)', 'Posko PIC'],
                $typeTable
            );
        } else {
            $this->line('  2. TYPE MISMATCH: 0 item (OK)');
        }

        // 3. Blank Posko PIC
        $blankPosko = $sectionPicData['blank_posko_rows'];
        if ($blankPosko !== []) {
            $this->newLine();
            $this->warn('3. BLANK POSKO PIC pada Section PIC:');
            $blankTable = [];
            $no = 1;
            foreach (array_slice($blankPosko, 0, $limit) as $bp) {
                $blankTable[] = [
                    $no++,
                    $bp['row_number'] ?? '-',
                    $bp['feeder'] ?? '(NULL)',
                    $bp['raw_pemutus'] ?? '-',
                ];
            }
            $this->table(['No', 'Source Row', 'Feeder (Derived)', 'Pemutus (Raw)'], $blankTable);
        } else {
            $this->line('  3. BLANK POSKO PIC: 0 item (OK)');
        }

        // 4. Duplicate Keypoint Rows
        $duplicateKp = $keypointData['duplicate_rows'];
        if ($duplicateKp !== []) {
            $this->newLine();
            $this->warn('4. DUPLICATE KEYPOINT (Nama + Feeder Sama):');
            $dupTable = [];
            $no = 1;
            foreach (array_slice($duplicateKp, 0, $limit) as $dk) {
                $dupTable[] = [
                    $no++,
                    $dk['row_number'] ?? '-',
                    $dk['feeder'] ?? '-',
                    $dk['nama'] ?? '-',
                    $dk['jenis'] ?? '-',
                    $dk['koordinat'] ?? '-',
                ];
            }
            $this->table(['No', 'Source Row', 'Feeder', 'Nama Keypoint', 'Jenis', 'Koordinat'], $dupTable);
        } else {
            $this->line('  4. DUPLICATE KEYPOINT: 0 item (OK)');
        }

        // 5. Proximity Outliers (>50m atau Unmatched)
        $outliers = $proximityData['outliers'] ?? [];
        if ($outliers !== []) {
            $this->newLine();
            $this->warn('5. PROXIMITY OUTLIERS (>50 Meter atau Unmatched ke Asset):');
            $outlierTable = [];
            $no = 1;
            foreach (array_slice($outliers, 0, $limit) as $ot) {
                $distStr = $ot['distance_meter'] !== null
                    ? number_format($ot['distance_meter'], 2).' m'
                    : 'N/A';

                $outlierTable[] = [
                    $no++,
                    $ot['keypoint_row'] ?? '-',
                    $ot['keypoint_feeder'],
                    $ot['keypoint_name'],
                    $ot['keypoint_coord'],
                    $ot['nearest_id_aset'],
                    $ot['nearest_nama_aset'],
                    $distStr." ({$ot['bucket']})",
                ];
            }
            $this->table(
                ['No', 'Row', 'Feeder', 'Nama Keypoint', 'Koordinat', 'Nearest ID_ASET', 'Nearest NAMA_ASET', 'Jarak (Bucket)'],
                $outlierTable
            );
        } else {
            $this->line('  5. PROXIMITY OUTLIERS (>50m): 0 item (OK)');
        }

        return [
            'conflicts_count' => count($conflicts),
            'type_mismatches_count' => count($typeMismatches),
            'blank_posko_count' => count($blankPosko),
            'duplicate_keypoint_count' => count($duplicateKp),
            'outliers_count' => count($outliers),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION F: KEYPOINT → ASSET PROXIMITY AUDIT
    // ─────────────────────────────────────────────────────────────────────────

    public function auditKeypointAssetProximity(array $keypointData, int $limit = 20): array
    {
        if ($this->output !== null) {
            $this->newLine();
            $this->info('🎯 F. KEYPOINT → ASSET PROXIMITY AUDIT');
            $this->line('  Mencari candidate nearest asset pada feeder yang sama menggunakan Haversine.');
            $this->line('  Catatan: candidate nearest asset adalah indikator kedekatan spasial, bukan relasi definitif.');
            $this->line(str_repeat('─', 78));
        }

        $totalAssets = AssetSourceRow::count();
        if ($totalAssets === 0) {
            if ($this->output !== null) {
                $this->warn('  Tabel asset_source_rows kosong (0 baris). Proximity audit dilewati.');
            }

            return [
                'total_assets_indexed' => 0,
                'distribution' => [
                    'lte_1m' => 0,
                    '1_to_5m' => 0,
                    '5_to_20m' => 0,
                    '20_to_50m' => 0,
                    'gt_50m' => 0,
                    'unmatched' => 0,
                ],
                'results' => [],
                'outliers' => [],
            ];
        }

        // Index assets by canonical feeder
        $assetsByFeeder = [];
        $assetCount = 0;

        foreach (AssetSourceRow::query()
            ->select(['id_aset_raw', 'nama_aset_raw', 'penyulang_raw', 'latitude_raw', 'longitude_raw'])
            ->lazy(2000) as $asset) {
            $coords = $this->parseAssetCoordinates($asset->latitude_raw, $asset->longitude_raw);
            if ($coords === null) {
                continue;
            }

            $feeder = $this->canonicalFeeder($asset->penyulang_raw);
            if ($feeder === '') {
                continue;
            }

            $assetsByFeeder[$feeder][] = [
                'id_aset' => $this->cleanString($asset->id_aset_raw),
                'nama_aset' => $this->cleanString($asset->nama_aset_raw),
                'lat' => $coords['lat'],
                'lon' => $coords['lon'],
            ];
            $assetCount++;
        }

        $bucketLte1m = 0;
        $bucket1to5m = 0;
        $bucket5to20m = 0;
        $bucket20to50m = 0;
        $bucketGt50m = 0;
        $bucketUnmatched = 0;

        $proximityResults = [];
        $outliers = [];

        foreach ($keypointData['keypoint_rows'] as $kp) {
            $kpFeeder = $kp['canonical_feeder'];
            $coord = $kp['parsed_coord'];
            $kpName = $kp['raw_name'] ?? '(BLANK)';
            $coordStr = $kp['raw_coord'] ?? '-';
            $kpRowNumber = $kp['source_row_number'];

            if ($coord === null || $kpFeeder === '') {
                $bucketUnmatched++;
                $item = [
                    'keypoint_row' => $kpRowNumber,
                    'keypoint_feeder' => $kpFeeder,
                    'keypoint_name' => $kpName,
                    'keypoint_coord' => $coordStr,
                    'nearest_id_aset' => '-',
                    'nearest_nama_aset' => '-',
                    'distance_meter' => null,
                    'bucket' => 'tidak dapat dicocokkan',
                ];
                $proximityResults[] = $item;
                $outliers[] = $item;

                continue;
            }

            $candidateAssets = $assetsByFeeder[$kpFeeder] ?? [];

            if ($candidateAssets === []) {
                $bucketUnmatched++;
                $item = [
                    'keypoint_row' => $kpRowNumber,
                    'keypoint_feeder' => $kpFeeder,
                    'keypoint_name' => $kpName,
                    'keypoint_coord' => $coordStr,
                    'nearest_id_aset' => '-',
                    'nearest_nama_aset' => '(Tidak ada aset pada feeder)',
                    'distance_meter' => null,
                    'bucket' => 'tidak dapat dicocokkan',
                ];
                $proximityResults[] = $item;
                $outliers[] = $item;

                continue;
            }

            $minDist = null;
            $nearestAsset = null;

            foreach ($candidateAssets as $ast) {
                $dist = $this->haversineDistance($coord['lat'], $coord['lon'], $ast['lat'], $ast['lon']);

                if ($minDist === null || $dist < $minDist) {
                    $minDist = $dist;
                    $nearestAsset = $ast;
                }
            }

            if ($minDist <= 1.0) {
                $bucket = '<= 1 meter';
                $bucketLte1m++;
            } elseif ($minDist <= 5.0) {
                $bucket = '>1 sampai 5 meter';
                $bucket1to5m++;
            } elseif ($minDist <= 20.0) {
                $bucket = '>5 sampai 20 meter';
                $bucket5to20m++;
            } elseif ($minDist <= 50.0) {
                $bucket = '>20 sampai 50 meter';
                $bucket20to50m++;
            } else {
                $bucket = '>50 meter';
                $bucketGt50m++;
            }

            $item = [
                'keypoint_row' => $kpRowNumber,
                'keypoint_feeder' => $kpFeeder,
                'keypoint_name' => $kpName,
                'keypoint_coord' => $coordStr,
                'nearest_id_aset' => $nearestAsset['id_aset'] ?? '-',
                'nearest_nama_aset' => $nearestAsset['nama_aset'] ?? '-',
                'distance_meter' => $minDist,
                'bucket' => $bucket,
            ];

            $proximityResults[] = $item;

            if ($minDist > 50.0) {
                $outliers[] = $item;
            }
        }

        $totalKp = count($keypointData['keypoint_rows']);

        if ($this->output !== null) {
            $this->table(
                ['Distribusi Jarak ke Nearest Asset', 'Jumlah Keypoint', 'Persentase'],
                [
                    ['<= 1 meter', number_format($bucketLte1m), $totalKp > 0 ? round(($bucketLte1m / $totalKp) * 100, 1).'%' : '-'],
                    ['>1 sampai 5 meter', number_format($bucket1to5m), $totalKp > 0 ? round(($bucket1to5m / $totalKp) * 100, 1).'%' : '-'],
                    ['>5 sampai 20 meter', number_format($bucket5to20m), $totalKp > 0 ? round(($bucket5to20m / $totalKp) * 100, 1).'%' : '-'],
                    ['>20 sampai 50 meter', number_format($bucket20to50m), $totalKp > 0 ? round(($bucket20to50m / $totalKp) * 100, 1).'%' : '-'],
                    ['>50 meter', number_format($bucketGt50m), $totalKp > 0 ? round(($bucketGt50m / $totalKp) * 100, 1).'%' : '-'],
                    ['tidak dapat dicocokkan (koordinat/feeder invalid)', number_format($bucketUnmatched), $totalKp > 0 ? round(($bucketUnmatched / $totalKp) * 100, 1).'%' : '-'],
                    ['Total Keypoint Dianalisis', number_format($totalKp), '100%'],
                ]
            );

            // Candidate Nearest Assets Table
            $this->newLine();
            $this->line("Daftar Candidate Nearest Asset (Maks {$limit} baris pertama):");

            $sampleResults = array_slice($proximityResults, 0, $limit);
            $candidateTable = [];
            $no = 1;

            foreach ($sampleResults as $res) {
                $distDisplay = $res['distance_meter'] !== null
                    ? number_format($res['distance_meter'], 2).' m ('.$res['bucket'].')'
                    : 'N/A ('.$res['bucket'].')';

                $candidateTable[] = [
                    $no++,
                    $res['keypoint_feeder'],
                    $res['keypoint_name'],
                    $res['keypoint_coord'],
                    $res['nearest_id_aset'],
                    $res['nearest_nama_aset'],
                    $distDisplay,
                ];
            }

            $this->table(
                ['No', 'Keypoint Feeder', 'Keypoint Name', 'Koordinat', 'Nearest ID_ASET', 'Nearest NAMA_ASET', 'Jarak (Meter)'],
                $candidateTable
            );
        }

        return [
            'total_assets_indexed' => $assetCount,
            'distribution' => [
                'lte_1m' => $bucketLte1m,
                '1_to_5m' => $bucket1to5m,
                '5_to_20m' => $bucket5to20m,
                '20_to_50m' => $bucket20to50m,
                'gt_50m' => $bucketGt50m,
                'unmatched' => $bucketUnmatched,
            ],
            'results' => $proximityResults,
            'outliers' => $outliers,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER FUNCTIONS (IN-MEMORY ONLY)
    // ─────────────────────────────────────────────────────────────────────────

    public function cleanString(mixed $value): string
    {
        return trim(
            preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)
        );
    }

    /**
     * Normalisasi canonical feeder dengan alias yang disetujui:
     * TANGGAMUS -> TANGGAMUS 2.
     */
    public function canonicalFeeder(?string $feeder): string
    {
        $feeder = mb_strtoupper($this->cleanString($feeder));
        if ($feeder === 'TANGGAMUS') {
            return 'TANGGAMUS 2';
        }

        return $feeder;
    }

    /**
     * Derive expected equipment type from raw equipment name prefix:
     * REC / RECLOSER -> RECLOSER
     * LBSM / LBS M -> LBSM
     * FCO -> FCO
     */
    public function deriveEquipmentType(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = mb_strtoupper($this->cleanString($name));
        if ($name === '') {
            return null;
        }

        $cleaned = preg_replace('/[-._\/]+/', ' ', $name);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));

        if (preg_match('/^(RECLOSER|REC)(\s+.*)?$/i', $cleaned)) {
            return 'RECLOSER';
        }

        if (preg_match('/^(LBS\s*M|LBSM)(\s+.*)?$/i', $cleaned)) {
            return 'LBSM';
        }

        if (preg_match('/^FCO(\s+.*)?$/i', $cleaned)) {
            return 'FCO';
        }

        return null;
    }

    /**
     * Normalize keypoint type (jenis_raw).
     */
    public function normalizeKeypointType(?string $jenis): ?string
    {
        if ($jenis === null) {
            return null;
        }

        $jenis = mb_strtoupper($this->cleanString($jenis));
        if ($jenis === '') {
            return null;
        }

        if (in_array($jenis, ['REC', 'RECLOSER'], true)) {
            return 'RECLOSER';
        }

        if (in_array($jenis, ['LBSM', 'LBS M', 'LBS-M'], true)) {
            return 'LBSM';
        }

        if ($jenis === 'FCO') {
            return 'FCO';
        }

        return $jenis;
    }

    /**
     * Safe name normalization untuk audit:
     * - uppercase
     * - trim
     * - collapse multiple spaces
     * - replace simple punctuation (- . _ /) with space
     * - type prefix normalization:
     *   REC / RECLOSER -> RECLOSER
     *   LBS M / LBSM -> LBSM
     *   FCO -> FCO
     */
    public function normalizeEquipmentName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $name = $this->cleanString($name);
        if ($name === '') {
            return '';
        }

        $name = mb_strtoupper($name);

        // Ganti tanda baca umum (hyphen, dot, underscore, slash) menjadi spasi
        $name = preg_replace('/[-._\/]+/', ' ', $name);

        // Collapse multiple spaces & trim
        $name = trim(preg_replace('/\s+/', ' ', $name));

        // Normalisasi prefix tipe peralatan:
        // 1. RECLOSER / REC
        if (preg_match('/^RECLOSER(\s+.*)?$/i', $name, $matches)) {
            $rest = isset($matches[1]) ? trim($matches[1]) : '';
            $name = $rest !== '' ? 'RECLOSER '.$rest : 'RECLOSER';
        } elseif (preg_match('/^REC(\s+.*)?$/i', $name, $matches)) {
            $rest = isset($matches[1]) ? trim($matches[1]) : '';
            $name = $rest !== '' ? 'RECLOSER '.$rest : 'RECLOSER';
        }
        // 2. LBS M / LBSM
        elseif (preg_match('/^LBS\s*M(\s+.*)?$/i', $name, $matches)) {
            $rest = isset($matches[1]) ? trim($matches[1]) : '';
            $name = $rest !== '' ? 'LBSM '.$rest : 'LBSM';
        }
        // 3. FCO
        elseif (preg_match('/^FCO(\s+.*)?$/i', $name, $matches)) {
            $rest = isset($matches[1]) ? trim($matches[1]) : '';
            $name = $rest !== '' ? 'FCO '.$rest : 'FCO';
        }

        return trim($name);
    }

    /**
     * Parse koordinat string keypoint: "lat, lon".
     *
     * @return ?array{lat: float, lon: float, raw_lat: string, raw_lon: string}
     */
    public function parseCoordinateString(?string $coordString): ?array
    {
        $cleaned = $this->cleanString($coordString);
        if ($cleaned === '') {
            return null;
        }

        if (str_contains($cleaned, ',')) {
            $parts = explode(',', $cleaned);
            if (count($parts) === 2) {
                $latStr = trim($parts[0]);
                $lonStr = trim($parts[1]);
            } elseif (count($parts) === 4) {
                $latStr = trim($parts[0]).'.'.trim($parts[1]);
                $lonStr = trim($parts[2]).'.'.trim($parts[3]);
            } else {
                return null;
            }
        } else {
            $parts = preg_split('/\s+/', $cleaned);
            if (count($parts) === 2) {
                $latStr = trim($parts[0]);
                $lonStr = trim($parts[1]);
            } else {
                return null;
            }
        }

        $latNorm = str_replace(',', '.', $latStr);
        $lonNorm = str_replace(',', '.', $lonStr);

        if (! is_numeric($latNorm) || ! is_numeric($lonNorm)) {
            return null;
        }

        $lat = (float) $latNorm;
        $lon = (float) $lonNorm;

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return null;
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'raw_lat' => $latStr,
            'raw_lon' => $lonStr,
        ];
    }

    /**
     * Parse koordinat latitude dan longitude terpisah dari asset_source_rows.
     *
     * @return ?array{lat: float, lon: float}
     */
    public function parseAssetCoordinates(?string $latRaw, ?string $lonRaw): ?array
    {
        $latClean = $this->cleanString($latRaw);
        $lonClean = $this->cleanString($lonRaw);

        if ($latClean === '' || $lonClean === '') {
            return null;
        }

        $latNorm = str_replace(',', '.', $latClean);
        $lonNorm = str_replace(',', '.', $lonClean);

        if (! is_numeric($latNorm) || ! is_numeric($lonNorm)) {
            return null;
        }

        $lat = (float) $latNorm;
        $lon = (float) $lonNorm;

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return null;
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
        ];
    }

    /**
     * Hitung jarak dua koordinat GPS menggunakan formula Haversine (meter).
     */
    public function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // Radius bumi dalam meter

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1.0 - $a));

        return $earthRadius * $c;
    }
}
