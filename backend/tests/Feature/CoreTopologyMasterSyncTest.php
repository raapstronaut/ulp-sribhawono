<?php

namespace Tests\Feature;

use App\Console\Commands\SyncCoreTopologyMasters;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\SectionPicSourceRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreTopologyMasterSyncTest extends TestCase
{
    use RefreshDatabase;

    private SyncCoreTopologyMasters $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new SyncCoreTopologyMasters();
    }

    // ─── 1. Command Registration ─────────────────────────────────────────

    public function test_sync_core_masters_command_is_registered(): void
    {
        $this->artisan('topology:sync-core-masters', ['--help' => true])
            ->assertExitCode(0);
    }

    // ─── 2. Database Schema Verification ─────────────────────────────────

    public function test_core_topology_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('poskos'));
        $this->assertTrue(Schema::hasColumns('poskos', [
            'id', 'kode_posko', 'nama_posko', 'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('penyulangs'));
        $this->assertTrue(Schema::hasColumns('penyulangs', [
            'id', 'kode_penyulang', 'nama_penyulang', 'gardu_induk', 'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('posko_penyulang'));
        $this->assertTrue(Schema::hasColumns('posko_penyulang', [
            'posko_id', 'penyulang_id', 'created_at', 'updated_at',
        ]));
    }

    // ─── 3. Empty SECTION PIC Aborts Without Creating Masters ────────────

    public function test_empty_section_pic_aborts_and_creates_no_masters(): void
    {
        // Staging table is completely empty (0 rows)
        $this->assertSame(0, SectionPicSourceRow::count());

        $result = $this->command->syncCoreMasters();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('kosong', $result['message']);

        // No Posko, no Penyulang, no pivot must be created
        $this->assertSame(0, Posko::count());
        $this->assertSame(0, Penyulang::count());
        $this->assertSame(0, DB::table('posko_penyulang')->count());

        // Command CLI returns failure exit code
        $this->artisan('topology:sync-core-masters')
            ->assertExitCode(1);
    }

    // ─── 4. 6 Official Poskos Created Uniquely ───────────────────────────

    public function test_six_official_poskos_are_created_uniquely(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertCount(6, $result['poskos_synced']);
        $this->assertSame(6, Posko::count());

        $expectedCodes = [
            'SRIBHAWONO', 'PUGUNG', 'JABUNG',
            'PASIR SAKTI', 'WAY JEPARA', 'TRIDATU',
        ];

        foreach ($expectedCodes as $code) {
            $this->assertDatabaseHas('poskos', [
                'kode_posko' => $code,
                'nama_posko' => $code,
                'is_active' => true,
            ]);
        }
    }

    // ─── 5. 16 Canonical Feeders Created from Representative Fixture ──────

    public function test_sixteen_canonical_feeders_created_from_fixture(): void
    {
        $canonicalFeeders = [
            'BAUNG 2', 'BEDANA', 'CAKALELE', 'GAMBYONG',
            'JAIPONG', 'JATILAN', 'KECAK', 'KUNINGAN 2',
            'NANAS', 'PAYUNG', 'REOG', 'RONGGENG',
            'SAMAN', 'TANGGAMUS 2', 'TOR TOR', 'ZAPIN',
        ];

        // Seed 16 feeders into staging
        foreach ($canonicalFeeders as $i => $feeder) {
            SectionPicSourceRow::create([
                'penyulang_raw' => $feeder,
                'pemutus_raw' => 'SWITCH '.$feeder,
                'posko_pic_raw' => 'SRIBHAWONO',
                'source_row_number' => $i + 2,
            ]);
        }

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertCount(16, $result['penyulangs_synced']);
        $this->assertSame(16, Penyulang::count());

        foreach ($canonicalFeeders as $feeder) {
            $this->assertDatabaseHas('penyulangs', [
                'kode_penyulang' => $feeder,
                'nama_penyulang' => $feeder,
                'is_active' => true,
            ]);
        }
    }

    // ─── 6. Canonical Alias TANGGAMUS → TANGGAMUS 2 ──────────────────────

    public function test_tanggamus_alias_is_canonicalized_to_tanggamus_2(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'TANGGAMUS',
            'pemutus_raw' => 'GI KETAPANG P. TANGGAMUS',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('penyulangs', [
            'kode_penyulang' => 'TANGGAMUS 2',
        ]);
        $this->assertDatabaseMissing('penyulangs', [
            'kode_penyulang' => 'TANGGAMUS',
        ]);
    }

    // ─── 7. Unknown Feeder Rejected (Whitelist Enforcement) ───────────────

    public function test_unknown_feeder_is_not_created_and_produces_no_pivot(): void
    {
        // JAIPONG (whitelisted) and FEEDER_GHAIB (unknown / not whitelisted)
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        SectionPicSourceRow::create([
            'penyulang_raw' => 'FEEDER_GHAIB',
            'pemutus_raw' => 'REC GHAIB',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 3,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['unknown_feeder_rows']);
        $this->assertSame('FEEDER_GHAIB', $result['unknown_feeder_rows'][0]['canonical_feeder']);

        // Only JAIPONG should be created
        $this->assertDatabaseHas('penyulangs', [
            'kode_penyulang' => 'JAIPONG',
        ]);
        $this->assertDatabaseMissing('penyulangs', [
            'kode_penyulang' => 'FEEDER_GHAIB',
        ]);
        $this->assertSame(1, Penyulang::count());
    }

    // ─── 8. Existing Masters Updated Without Changing UUIDs ──────────────

    public function test_existing_posko_and_penyulang_updated_without_changing_uuid(): void
    {
        // Pre-create Posko and Penyulang with specific UUID and old name/status
        $existingPosko = Posko::create([
            'kode_posko' => 'SRIBHAWONO',
            'nama_posko' => 'Nama Lama',
            'is_active' => false,
        ]);
        $poskoIdBefore = (string) $existingPosko->id;

        $existingPenyulang = Penyulang::create([
            'kode_penyulang' => 'JAIPONG',
            'nama_penyulang' => 'Nama Penyulang Lama',
            'is_active' => false,
        ]);
        $penyulangIdBefore = (string) $existingPenyulang->id;

        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $this->command->syncCoreMasters();

        $existingPosko->refresh();
        $existingPenyulang->refresh();

        // UUID must remain identical
        $this->assertSame($poskoIdBefore, (string) $existingPosko->id);
        $this->assertSame($penyulangIdBefore, (string) $existingPenyulang->id);

        // Fields must be update-safely synced
        $this->assertSame('SRIBHAWONO', $existingPosko->nama_posko);
        $this->assertTrue($existingPosko->is_active);
        $this->assertSame('JAIPONG', $existingPenyulang->nama_penyulang);
        $this->assertTrue($existingPenyulang->is_active);

        // No duplicate records created
        $this->assertSame(6, Posko::count());
        $this->assertSame(1, Penyulang::count());
    }

    // ─── 9. Stale Pivot Reconciled / Detached ─────────────────────────────

    public function test_stale_pivot_is_reconciled_and_detached(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC 1',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 2',
            'posko_pic_raw' => 'PASIR SAKTI',
            'source_row_number' => 3,
        ]);

        // Run 1: JAIPONG is mapped to SRIBHAWONO and PASIR SAKTI (2 pivots)
        $this->command->syncCoreMasters();

        $jaipong = Penyulang::where('kode_penyulang', 'JAIPONG')->first();
        $this->assertCount(2, $jaipong->poskos);

        // Now update source: JAIPONG is now ONLY mapped to SRIBHAWONO (PASIR SAKTI removed)
        SectionPicSourceRow::query()->delete();
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC 1',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        // Run 2: Reconciles pivot
        $this->command->syncCoreMasters();

        $jaipong->refresh();
        $this->assertCount(1, $jaipong->poskos);
        $this->assertSame('SRIBHAWONO', $jaipong->poskos->first()->kode_posko);

        // Master Posko PASIR SAKTI must still exist in poskos table!
        $this->assertDatabaseHas('poskos', ['kode_posko' => 'PASIR SAKTI']);
    }

    // ─── 10. Multi-Posko Feeder Supported ────────────────────────────────

    public function test_multi_posko_feeder_is_supported(): void
    {
        // KECAK maps to SRIBHAWONO, WAY JEPARA, and TRIDATU
        SectionPicSourceRow::create([
            'penyulang_raw' => 'KECAK',
            'pemutus_raw' => 'REC 1',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 2',
            'posko_pic_raw' => 'WAY JEPARA',
            'source_row_number' => 3,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 3',
            'posko_pic_raw' => 'TRIDATU',
            'source_row_number' => 4,
        ]);

        // JAIPONG maps to SRIBHAWONO and PASIR SAKTI
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC 4',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 5,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 5',
            'posko_pic_raw' => 'PASIR SAKTI',
            'source_row_number' => 6,
        ]);

        $this->command->syncCoreMasters();

        $kecak = Penyulang::where('kode_penyulang', 'KECAK')->first();
        $this->assertNotNull($kecak);
        $this->assertCount(3, $kecak->poskos);

        $kecakPoskoCodes = $kecak->poskos->pluck('kode_posko')->sort()->values()->all();
        $this->assertSame(['SRIBHAWONO', 'TRIDATU', 'WAY JEPARA'], $kecakPoskoCodes);

        $jaipong = Penyulang::where('kode_penyulang', 'JAIPONG')->first();
        $this->assertNotNull($jaipong);
        $this->assertCount(2, $jaipong->poskos);

        $jaipongPoskoCodes = $jaipong->poskos->pluck('kode_posko')->sort()->values()->all();
        $this->assertSame(['PASIR SAKTI', 'SRIBHAWONO'], $jaipongPoskoCodes);
    }

    // ─── 11. Duplicate Source Rows Do Not Produce Duplicate Pivots ────────

    public function test_duplicate_source_rows_do_not_produce_duplicate_pivot(): void
    {
        // 3 rows with same feeder and same posko
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'LBSM KARYA TANI',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 3,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'LBSM LAINNYA',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 4,
        ]);

        $this->command->syncCoreMasters();

        $jaipong = Penyulang::where('kode_penyulang', 'JAIPONG')->first();
        $sribhawono = Posko::where('kode_posko', 'SRIBHAWONO')->first();

        // Must have exactly 1 pivot record in posko_penyulang
        $pivotCount = DB::table('posko_penyulang')
            ->where('penyulang_id', $jaipong->id)
            ->where('posko_id', $sribhawono->id)
            ->count();

        $this->assertSame(1, $pivotCount);
    }

    // ─── 12. Blank POSKO PIC Does Not Produce Pivot ───────────────────────

    public function test_blank_posko_pic_does_not_produce_pivot(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'KUNINGAN 2',
            'pemutus_raw' => 'IR SUTAMI',
            'posko_pic_raw' => '', // Blank posko
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $kuningan = Penyulang::where('kode_penyulang', 'KUNINGAN 2')->first();
        $this->assertNotNull($kuningan);
        $this->assertCount(0, $kuningan->poskos);

        $this->assertCount(1, $result['blank_posko_rows']);
        $this->assertSame('KUNINGAN 2', $result['blank_posko_rows'][0]['feeder']);
    }

    // ─── 13. Unknown Posko Is Not Created Silently ────────────────────────

    public function test_unknown_posko_is_not_created_silently(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'POSKO_GHAIB', // Unknown posko
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertDatabaseMissing('poskos', [
            'kode_posko' => 'POSKO_GHAIB',
        ]);
        $this->assertSame(6, Posko::count()); // Still only 6 official poskos

        $this->assertCount(1, $result['unknown_posko_rows']);
        $this->assertSame('POSKO_GHAIB', $result['unknown_posko_rows'][0]['unknown_posko']);
    }

    // ─── 14. Derived Fill-Down Preserves Staging RAW ─────────────────────

    public function test_derived_fill_down_preserves_staging_raw(): void
    {
        $row1 = SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $row2 = SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'LBSM KARYA TANI',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 3,
        ]);

        $this->command->syncCoreMasters();

        $row1->refresh();
        $row2->refresh();

        $this->assertSame('JAIPONG', $row1->penyulang_raw);
        $this->assertNull($row2->penyulang_raw); // Staging NULL remains unchanged
    }

    // ─── 15. Command Idempotency ─────────────────────────────────────────

    public function test_sync_core_masters_command_is_idempotent(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 2',
            'posko_pic_raw' => 'PASIR SAKTI',
            'source_row_number' => 3,
        ]);

        // Run 1
        $this->command->syncCoreMasters();

        $poskoCount1 = Posko::count();
        $penyulangCount1 = Penyulang::count();
        $pivotCount1 = DB::table('posko_penyulang')->count();
        $jaipongId1 = Penyulang::where('kode_penyulang', 'JAIPONG')->first()->id;

        // Run 2
        $this->command->syncCoreMasters();

        $poskoCount2 = Posko::count();
        $penyulangCount2 = Penyulang::count();
        $pivotCount2 = DB::table('posko_penyulang')->count();
        $jaipongId2 = Penyulang::where('kode_penyulang', 'JAIPONG')->first()->id;

        $this->assertSame($poskoCount1, $poskoCount2);
        $this->assertSame($penyulangCount1, $penyulangCount2);
        $this->assertSame($pivotCount1, $pivotCount2);
        $this->assertSame($jaipongId1, $jaipongId2); // IDs do not change
    }

    // ─── 16. Blank & Unknown Values Do Not Break Valid Relations ──────────

    public function test_blank_and_unknown_values_do_not_corrupt_valid_relations(): void
    {
        // Valid relation
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        // Row with blank Posko on JAIPONG
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 2',
            'posko_pic_raw' => '',
            'source_row_number' => 3,
        ]);

        // Row with unknown Posko on JAIPONG
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 3',
            'posko_pic_raw' => 'POSKO_PALSU',
            'source_row_number' => 4,
        ]);

        // Row with unknown Feeder
        SectionPicSourceRow::create([
            'penyulang_raw' => 'FEEDER_UNKNOWN',
            'pemutus_raw' => 'REC 4',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 5,
        ]);

        // Another valid relation on JATILAN
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JATILAN',
            'pemutus_raw' => 'REC 5',
            'posko_pic_raw' => 'PUGUNG',
            'source_row_number' => 6,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);

        // JAIPONG must still be correctly mapped to SRIBHAWONO
        $jaipong = Penyulang::where('kode_penyulang', 'JAIPONG')->first();
        $this->assertNotNull($jaipong);
        $this->assertCount(1, $jaipong->poskos);
        $this->assertSame('SRIBHAWONO', $jaipong->poskos->first()->kode_posko);

        // JATILAN must still be correctly mapped to PUGUNG
        $jatilan = Penyulang::where('kode_penyulang', 'JATILAN')->first();
        $this->assertNotNull($jatilan);
        $this->assertCount(1, $jatilan->poskos);
        $this->assertSame('PUGUNG', $jatilan->poskos->first()->kode_posko);
    }

    // ─── 17. Incomplete Feeder Safety: Blank Posko Preserves Existing Pivot ──

    public function test_existing_pivot_survives_when_feeder_only_source_row_has_blank_posko(): void
    {
        $sribhawono = Posko::create([
            'kode_posko' => 'SRIBHAWONO',
            'nama_posko' => 'SRIBHAWONO',
            'is_active' => true,
        ]);
        $jaipong = Penyulang::create([
            'kode_penyulang' => 'JAIPONG',
            'nama_penyulang' => 'JAIPONG',
            'is_active' => true,
        ]);
        $jaipong->poskos()->attach($sribhawono->id);

        $this->assertCount(1, $jaipong->poskos);

        // Staging row has blank POSKO for JAIPONG
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => '',
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['blank_posko_rows']);

        $jaipong->refresh();
        // Existing pivot must survive and NOT be detached
        $this->assertCount(1, $jaipong->poskos);
        $this->assertSame('SRIBHAWONO', $jaipong->poskos->first()->kode_posko);
    }

    // ─── 18. Incomplete Feeder Safety: Unknown Posko Preserves Existing Pivot ─

    public function test_existing_pivot_survives_when_feeder_only_source_row_has_unknown_posko(): void
    {
        $sribhawono = Posko::create([
            'kode_posko' => 'SRIBHAWONO',
            'nama_posko' => 'SRIBHAWONO',
            'is_active' => true,
        ]);
        $jaipong = Penyulang::create([
            'kode_penyulang' => 'JAIPONG',
            'nama_penyulang' => 'JAIPONG',
            'is_active' => true,
        ]);
        $jaipong->poskos()->attach($sribhawono->id);

        $this->assertCount(1, $jaipong->poskos);

        // Staging row has unknown POSKO for JAIPONG
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'POSKO_GHAIB',
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['unknown_posko_rows']);

        $jaipong->refresh();
        // Existing pivot must survive and NOT be detached
        $this->assertCount(1, $jaipong->poskos);
        $this->assertSame('SRIBHAWONO', $jaipong->poskos->first()->kode_posko);
    }

    // ─── 19. Incomplete Feeder Safety: Add Valid Mapping Without Deleting ───

    public function test_valid_confirmed_mapping_added_on_incomplete_feeder_without_deleting_existing(): void
    {
        $sribhawono = Posko::create([
            'kode_posko' => 'SRIBHAWONO',
            'nama_posko' => 'SRIBHAWONO',
            'is_active' => true,
        ]);
        $pasirSakti = Posko::create([
            'kode_posko' => 'PASIR SAKTI',
            'nama_posko' => 'PASIR SAKTI',
            'is_active' => true,
        ]);
        $jaipong = Penyulang::create([
            'kode_penyulang' => 'JAIPONG',
            'nama_penyulang' => 'JAIPONG',
            'is_active' => true,
        ]);
        $jaipong->poskos()->attach($sribhawono->id);

        $this->assertCount(1, $jaipong->poskos);

        // Source has 1 valid mapping (PASIR SAKTI) and 1 blank posko row
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC 1',
            'posko_pic_raw' => 'PASIR SAKTI',
            'source_row_number' => 2,
        ]);
        SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'REC 2',
            'posko_pic_raw' => '',
            'source_row_number' => 3,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['blank_posko_rows']);

        $jaipong->refresh();
        // JAIPONG should have BOTH SRIBHAWONO (preserved) and PASIR SAKTI (newly attached)
        $this->assertCount(2, $jaipong->poskos);
        $poskoCodes = $jaipong->poskos->pluck('kode_posko')->sort()->values()->all();
        $this->assertSame(['PASIR SAKTI', 'SRIBHAWONO'], $poskoCodes);
    }

    // ─── 20. Clean Feeder Reconciles and Detaches Genuinely Stale Pivot ────

    public function test_clean_feeder_reconciles_and_detaches_genuinely_stale_pivot(): void
    {
        $sribhawono = Posko::create([
            'kode_posko' => 'SRIBHAWONO',
            'nama_posko' => 'SRIBHAWONO',
            'is_active' => true,
        ]);
        $pasirSakti = Posko::create([
            'kode_posko' => 'PASIR SAKTI',
            'nama_posko' => 'PASIR SAKTI',
            'is_active' => true,
        ]);
        $jaipong = Penyulang::create([
            'kode_penyulang' => 'JAIPONG',
            'nama_penyulang' => 'JAIPONG',
            'is_active' => true,
        ]);
        // JAIPONG initially mapped to both SRIBHAWONO and PASIR SAKTI
        $jaipong->poskos()->attach([$sribhawono->id, $pasirSakti->id]);
        $this->assertCount(2, $jaipong->poskos);

        // Source only maps JAIPONG to SRIBHAWONO with complete clean rows (no blank/unknown)
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC 1',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $result = $this->command->syncCoreMasters();

        $this->assertTrue($result['success']);
        $jaipong->refresh();
        // Clean feeder must detach PASIR SAKTI
        $this->assertCount(1, $jaipong->poskos);
        $this->assertSame('SRIBHAWONO', $jaipong->poskos->first()->kode_posko);
    }
}
