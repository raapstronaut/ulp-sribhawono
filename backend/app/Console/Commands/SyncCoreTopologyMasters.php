<?php

namespace App\Console\Commands;

use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\SectionPicSourceRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCoreTopologyMasters extends Command
{
    protected $signature = 'topology:sync-core-masters';

    protected $description = 'Sinkronisasi core master data (Posko, Penyulang, dan relasi Many-to-Many Posko ↔ Penyulang) dari staging SECTION_PIC_RAW';

    /**
     * 6 Posko Resmi ULP Sribhawono.
     */
    public const OFFICIAL_POSKOS = [
        'SRIBHAWONO' => 'SRIBHAWONO',
        'PUGUNG' => 'PUGUNG',
        'JABUNG' => 'JABUNG',
        'PASIR SAKTI' => 'PASIR SAKTI',
        'WAY JEPARA' => 'WAY JEPARA',
        'TRIDATU' => 'TRIDATU',
    ];

    /**
     * 16 Canonical Feeder Resmi ULP Sribhawono.
     */
    public const OFFICIAL_FEEDERS = [
        'BAUNG 2' => 'BAUNG 2',
        'BEDANA' => 'BEDANA',
        'CAKALELE' => 'CAKALELE',
        'GAMBYONG' => 'GAMBYONG',
        'JAIPONG' => 'JAIPONG',
        'JATILAN' => 'JATILAN',
        'KECAK' => 'KECAK',
        'KUNINGAN 2' => 'KUNINGAN 2',
        'NANAS' => 'NANAS',
        'PAYUNG' => 'PAYUNG',
        'REOG' => 'REOG',
        'RONGGENG' => 'RONGGENG',
        'SAMAN' => 'SAMAN',
        'TANGGAMUS 2' => 'TANGGAMUS 2',
        'TOR TOR' => 'TOR TOR',
        'ZAPIN' => 'ZAPIN',
    ];

    public function handle(): int
    {
        $this->writeHeader();

        $result = $this->syncCoreMasters();

        if (! $result['success']) {
            if ($this->output !== null) {
                $this->error($result['message'] ?? 'Sinkronisasi gagal.');
            }

            return self::FAILURE;
        }

        $this->writeSummary($result);

        return self::SUCCESS;
    }

    /**
     * Jalankan sinkronisasi core topology masters.
     *
     * @return array{
     *     success: bool,
     *     message: ?string,
     *     poskos_synced: list<array{id: string, kode: string, nama: string}>,
     *     penyulangs_synced: list<array{id: string, kode: string, nama: string}>,
     *     mappings_synced: list<array{feeder: string, posko: string}>,
     *     blank_posko_rows: list<array{row_number: ?int, feeder: string, raw_pemutus: ?string}>,
     *     unknown_posko_rows: list<array{row_number: ?int, feeder: string, raw_pemutus: ?string, unknown_posko: string}>,
     *     unknown_feeder_rows: list<array{row_number: ?int, raw_feeder: ?string, canonical_feeder: string, raw_pemutus: ?string, raw_posko: ?string}>,
     *     unresolved_feeder_rows: list<array{row_number: ?int, raw_pemutus: ?string, raw_posko: ?string}>
     * }
     */
    public function syncCoreMasters(): array
    {
        // ─── 1. Verifikasi Staging Table Tidak Kosong ───
        $rawRows = SectionPicSourceRow::query()
            ->orderBy('source_row_number', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($rawRows->isEmpty()) {
            return [
                'success' => false,
                'message' => 'Tabel staging section_pic_source_rows kosong. Sinkronisasi dibatalkan sebelum membuat master.',
                'poskos_synced' => [],
                'penyulangs_synced' => [],
                'mappings_synced' => [],
                'blank_posko_rows' => [],
                'unknown_posko_rows' => [],
                'unknown_feeder_rows' => [],
                'unresolved_feeder_rows' => [],
            ];
        }

        return DB::transaction(function () use ($rawRows) {
            // ─── 2. Upsert 6 Master Posko Resmi (Update-Safe, UUID Tetap) ───
            $poskoMap = [];
            $poskosSynced = [];

            foreach (self::OFFICIAL_POSKOS as $code => $name) {
                $posko = Posko::query()->updateOrCreate(
                    ['kode_posko' => $code],
                    ['nama_posko' => $name, 'is_active' => true]
                );

                $poskoMap[$code] = $posko;
                $poskosSynced[] = [
                    'id' => (string) $posko->id,
                    'kode' => $posko->kode_posko,
                    'nama' => $posko->nama_posko,
                ];
            }

            // ─── 3. Parse Staging Rows: Fill-Down + Whitelist Validation ───
            $currentFeeder = null;
            $validFeedersFound = [];
            $validPairs = [];
            $blankPoskoRows = [];
            $unknownPoskoRows = [];
            $unknownFeederRows = [];
            $unresolvedFeederRows = [];
            $feedersWithUncertainPosko = [];

            foreach ($rawRows as $row) {
                $rawFeeder = $this->cleanString($row->penyulang_raw);
                if ($rawFeeder !== '') {
                    $currentFeeder = $rawFeeder;
                }

                $derivedFeeder = $currentFeeder;
                if ($derivedFeeder === null || $derivedFeeder === '') {
                    $unresolvedFeederRows[] = [
                        'row_number' => $row->source_row_number,
                        'raw_pemutus' => $row->pemutus_raw,
                        'raw_posko' => $row->posko_pic_raw,
                    ];

                    continue;
                }

                $canonicalFeeder = $this->canonicalFeeder($derivedFeeder);

                // Validasi Whitelist Canonical Feeder
                if (! isset(self::OFFICIAL_FEEDERS[$canonicalFeeder])) {
                    $unknownFeederRows[] = [
                        'row_number' => $row->source_row_number,
                        'raw_feeder' => $row->penyulang_raw,
                        'canonical_feeder' => $canonicalFeeder,
                        'raw_pemutus' => $row->pemutus_raw,
                        'raw_posko' => $row->posko_pic_raw,
                    ];

                    continue;
                }

                $validFeedersFound[$canonicalFeeder] = true;

                $rawPosko = $this->cleanString($row->posko_pic_raw);
                if ($rawPosko === '') {
                    // Blank POSKO PIC tidak boleh menghasilkan mapping palsu
                    $blankPoskoRows[] = [
                        'row_number' => $row->source_row_number,
                        'feeder' => $canonicalFeeder,
                        'raw_pemutus' => $row->pemutus_raw,
                    ];
                    $feedersWithUncertainPosko[$canonicalFeeder] = true;

                    continue;
                }

                $normalizedPosko = mb_strtoupper($rawPosko);
                if (! isset(self::OFFICIAL_POSKOS[$normalizedPosko])) {
                    $unknownPoskoRows[] = [
                        'row_number' => $row->source_row_number,
                        'feeder' => $canonicalFeeder,
                        'raw_pemutus' => $row->pemutus_raw,
                        'unknown_posko' => $rawPosko,
                    ];
                    $feedersWithUncertainPosko[$canonicalFeeder] = true;

                    continue;
                }

                $validPairs[] = [
                    'feeder' => $canonicalFeeder,
                    'posko' => self::OFFICIAL_POSKOS[$normalizedPosko],
                ];
            }

            // ─── 4. Upsert Master Penyulang (Hanya Whitelisted, Update-Safe) ───
            $penyulangMap = [];
            $penyulangsSynced = [];

            // Sort feeders alphabetically for clean determinism
            $sortedFeeders = array_keys($validFeedersFound);
            sort($sortedFeeders);

            foreach ($sortedFeeders as $feederName) {
                $penyulang = Penyulang::query()->updateOrCreate(
                    ['kode_penyulang' => $feederName],
                    ['nama_penyulang' => $feederName, 'is_active' => true]
                );

                $penyulangMap[$feederName] = $penyulang;
                $penyulangsSynced[] = [
                    'id' => (string) $penyulang->id,
                    'kode' => $penyulang->kode_penyulang,
                    'nama' => $penyulang->nama_penyulang,
                ];
            }

            // ─── 5. Reconcile Many-to-Many Posko ↔ Penyulang Pivots ───
            $feederToPoskoIds = [];
            foreach ($penyulangMap as $feederName => $penyulangModel) {
                $feederToPoskoIds[$feederName] = [];
            }

            $uniquePairsMap = [];

            foreach ($validPairs as $pair) {
                $fdr = $pair['feeder'];
                $psk = $pair['posko'];

                if (isset($penyulangMap[$fdr], $poskoMap[$psk])) {
                    $poskoId = (string) $poskoMap[$psk]->id;
                    $feederToPoskoIds[$fdr][$poskoId] = true;

                    $pairKey = "{$fdr}|{$psk}";
                    $uniquePairsMap[$pairKey] = [
                        'feeder' => $fdr,
                        'posko' => $psk,
                    ];
                }
            }

            // Sync pivot table per feeder yang diproses dengan aturan safety:
            // 1. Clean feeder (hanya memiliki baris Posko valid): reconcile exact pivot set with sync().
            // 2. Incomplete feeder (memiliki baris blank/unknown Posko): preserve existing pivots dan attach valid mappings dengan syncWithoutDetaching().
            foreach ($penyulangMap as $feederName => $penyulang) {
                $validPoskoIds = array_keys($feederToPoskoIds[$feederName] ?? []);
                $hasUncertainPosko = isset($feedersWithUncertainPosko[$feederName]);

                if ($hasUncertainPosko) {
                    if (! empty($validPoskoIds)) {
                        $penyulang->poskos()->syncWithoutDetaching($validPoskoIds);
                    }
                } else {
                    $penyulang->poskos()->sync($validPoskoIds);
                }
            }

            return [
                'success' => true,
                'message' => 'Sinkronisasi core topology masters berhasil.',
                'poskos_synced' => $poskosSynced,
                'penyulangs_synced' => $penyulangsSynced,
                'mappings_synced' => array_values($uniquePairsMap),
                'blank_posko_rows' => $blankPoskoRows,
                'unknown_posko_rows' => $unknownPoskoRows,
                'unknown_feeder_rows' => $unknownFeederRows,
                'unresolved_feeder_rows' => $unresolvedFeederRows,
            ];
        });
    }

    private function writeHeader(): void
    {
        if ($this->output === null) {
            return;
        }

        $this->newLine();
        $this->line(str_repeat('═', 78));
        $this->info('  SYNC CORE TOPOLOGY MASTERS: POSKO & PENYULANG');
        $this->line(str_repeat('═', 78));
    }

    private function writeSummary(array $result): void
    {
        if ($this->output === null) {
            return;
        }

        $this->newLine();
        $this->info('1. MASTER POSKO SYNCED:');
        $poskoTable = [];
        $no = 1;
        foreach ($result['poskos_synced'] as $p) {
            $poskoTable[] = [$no++, $p['kode'], $p['nama'], $p['id']];
        }
        $this->table(['No', 'Kode Posko', 'Nama Posko', 'UUID'], $poskoTable);

        $this->newLine();
        $this->info('2. MASTER PENYULANG SYNCED:');
        $penyulangTable = [];
        $no = 1;
        foreach ($result['penyulangs_synced'] as $py) {
            $penyulangTable[] = [$no++, $py['kode'], $py['nama'], $py['id']];
        }
        $this->table(['No', 'Kode Penyulang', 'Nama Penyulang', 'UUID'], $penyulangTable);

        $this->newLine();
        $this->info('3. MANY-TO-MANY MAPPINGS (PENYULANG ↔ POSKO):');
        $mappingTable = [];
        $no = 1;
        foreach ($result['mappings_synced'] as $m) {
            $mappingTable[] = [$no++, $m['feeder'], $m['posko']];
        }
        $this->table(['No', 'Penyulang', 'Posko PIC'], $mappingTable);

        if ($result['blank_posko_rows'] !== []) {
            $this->newLine();
            $this->warn('⚠ Baris SECTION PIC dengan Blank POSKO PIC (tidak dibuat mapping):');
            $blankTable = [];
            foreach ($result['blank_posko_rows'] as $b) {
                $blankTable[] = [$b['row_number'] ?? '-', $b['feeder'], $b['raw_pemutus'] ?? '-'];
            }
            $this->table(['Row', 'Feeder', 'Pemutus'], $blankTable);
        }

        if ($result['unknown_posko_rows'] !== []) {
            $this->newLine();
            $this->error('⚠ Baris dengan POSKO tidak dikenal (ditolak):');
            $unknownTable = [];
            foreach ($result['unknown_posko_rows'] as $u) {
                $unknownTable[] = [$u['row_number'] ?? '-', $u['feeder'], $u['unknown_posko'], $u['raw_pemutus'] ?? '-'];
            }
            $this->table(['Row', 'Feeder', 'Unknown Posko', 'Pemutus'], $unknownTable);
        }

        if ($result['unknown_feeder_rows'] !== []) {
            $this->newLine();
            $this->error('⚠ Baris dengan FEEDER di luar Whitelist Resmi (ditolak):');
            $unknownFeederTable = [];
            foreach ($result['unknown_feeder_rows'] as $uf) {
                $unknownFeederTable[] = [
                    $uf['row_number'] ?? '-',
                    $uf['raw_feeder'] ?? '(NULL)',
                    $uf['canonical_feeder'],
                    $uf['raw_pemutus'] ?? '-',
                    $uf['raw_posko'] ?? '-',
                ];
            }
            $this->table(['Row', 'Raw Feeder', 'Canonical Feeder', 'Pemutus', 'Posko'], $unknownFeederTable);
        }

        $this->newLine();
        $this->line(str_repeat('═', 78));
        $this->info('  SYNC CORE TOPOLOGY MASTERS SELESAI');
        $this->line(str_repeat('═', 78));
        $this->newLine();
    }

    public function cleanString(mixed $value): string
    {
        return trim(
            preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)
        );
    }

    /**
     * Canonical feeder alias mapping:
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
}
