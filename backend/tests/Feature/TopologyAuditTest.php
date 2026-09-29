<?php

namespace Tests\Feature;

use App\Console\Commands\AuditTopology;
use App\Models\AssetSourceRow;
use App\Models\KeypointSourceRow;
use App\Models\SectionPicSourceRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyAuditTest extends TestCase
{
    use RefreshDatabase;

    private AuditTopology $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new AuditTopology();
    }

    // ─── 1. Command Registration ─────────────────────────────────────────

    public function test_topology_audit_command_is_registered(): void
    {
        $this->artisan('topology:audit', ['--help' => true])
            ->assertExitCode(0);
    }

    // ─── 2. Canonical Feeder Alias (TANGGAMUS → TANGGAMUS 2) ─────────────

    public function test_canonical_feeder_maps_tanggamus_alias(): void
    {
        $this->assertSame('TANGGAMUS 2', $this->command->canonicalFeeder('TANGGAMUS'));
        $this->assertSame('TANGGAMUS 2', $this->command->canonicalFeeder('tanggamus'));
        $this->assertSame('TANGGAMUS 2', $this->command->canonicalFeeder(' TANGGAMUS '));
        $this->assertSame('TANGGAMUS 2', $this->command->canonicalFeeder('TANGGAMUS 2'));
        $this->assertSame('JAIPONG', $this->command->canonicalFeeder('JAIPONG'));
        $this->assertSame('JATILAN', $this->command->canonicalFeeder('Jatilan'));
    }

    // ─── 3. Safe Name Normalization & Type Derivation ────────────────────

    public function test_safe_name_normalization_for_rec_and_recloser(): void
    {
        $this->assertSame('RECLOSER JABO', $this->command->normalizeEquipmentName('REC-JABO'));
        $this->assertSame('RECLOSER JABO', $this->command->normalizeEquipmentName('REC JABO'));
        $this->assertSame('RECLOSER JABO', $this->command->normalizeEquipmentName('RECLOSER JABO'));
        $this->assertSame('RECLOSER JABO', $this->command->normalizeEquipmentName('REC.JABO'));
        $this->assertSame('RECLOSER JABO', $this->command->normalizeEquipmentName('rec - jabo'));
        $this->assertSame('RECLOSER', $this->command->normalizeEquipmentName('REC'));
        $this->assertSame('RECLOSER', $this->command->normalizeEquipmentName('RECLOSER'));
    }

    public function test_safe_name_normalization_for_lbsm_and_fco(): void
    {
        $this->assertSame('LBSM KARYA TANI', $this->command->normalizeEquipmentName('LBS M KARYA TANI'));
        $this->assertSame('LBSM KARYA TANI', $this->command->normalizeEquipmentName('LBSM KARYA TANI'));
        $this->assertSame('LBSM KARYA TANI', $this->command->normalizeEquipmentName('LBS-M KARYA TANI'));
        $this->assertSame('LBSM KARYA TANI', $this->command->normalizeEquipmentName('LBS M. KARYA TANI'));
        $this->assertSame('FCO JAIPONG 01', $this->command->normalizeEquipmentName('FCO JAIPONG 01'));
        $this->assertSame('FCO JAIPONG 01', $this->command->normalizeEquipmentName('FCO-JAIPONG-01'));
        $this->assertSame('GI SRIBAWONO P JATILAN', $this->command->normalizeEquipmentName('GI SRIBAWONO P. JATILAN'));
    }

    public function test_derive_equipment_type_from_name(): void
    {
        $this->assertSame('RECLOSER', $this->command->deriveEquipmentType('REC JABO'));
        $this->assertSame('RECLOSER', $this->command->deriveEquipmentType('RECLOSER JABO'));
        $this->assertSame('RECLOSER', $this->command->deriveEquipmentType('REC-JABO'));
        $this->assertSame('LBSM', $this->command->deriveEquipmentType('LBSM KARYA TANI'));
        $this->assertSame('LBSM', $this->command->deriveEquipmentType('LBS M KARYA TANI'));
        $this->assertSame('FCO', $this->command->deriveEquipmentType('FCO JAIPONG 01'));
        $this->assertNull($this->command->deriveEquipmentType('GI SRIBAWONO P. JATILAN'));
        $this->assertNull($this->command->deriveEquipmentType(''));
        $this->assertNull($this->command->deriveEquipmentType(null));
    }

    // ─── 4. Coordinate Parsing ───────────────────────────────────────────

    public function test_coordinate_parsing_valid_strings(): void
    {
        $coord = $this->command->parseCoordinateString('-5.21099, 105.46007');
        $this->assertNotNull($coord);
        $this->assertEqualsWithDelta(-5.21099, $coord['lat'], 0.00001);
        $this->assertEqualsWithDelta(105.46007, $coord['lon'], 0.00001);

        $coord2 = $this->command->parseCoordinateString(' -5.08388 , 105.63628 ');
        $this->assertNotNull($coord2);
        $this->assertEqualsWithDelta(-5.08388, $coord2['lat'], 0.00001);
        $this->assertEqualsWithDelta(105.63628, $coord2['lon'], 0.00001);
    }

    public function test_coordinate_parsing_invalid_and_out_of_range(): void
    {
        $this->assertNull($this->command->parseCoordinateString(''));
        $this->assertNull($this->command->parseCoordinateString(null));
        $this->assertNull($this->command->parseCoordinateString('invalid_coord'));
        $this->assertNull($this->command->parseCoordinateString('-95.0, 105.0')); // Lat out of -90..90
        $this->assertNull($this->command->parseCoordinateString('-5.0, 190.0'));  // Lon out of -180..180
    }

    // ─── 5. Haversine Distance Calculation ───────────────────────────────

    public function test_haversine_distance_calculation(): void
    {
        // Same point -> 0 meters
        $distSame = $this->command->haversineDistance(-5.21099, 105.46007, -5.21099, 105.46007);
        $this->assertEqualsWithDelta(0.0, $distSame, 0.001);

        // Approximately 111 km per latitude degree
        $dist1Deg = $this->command->haversineDistance(0.0, 105.0, 1.0, 105.0);
        $this->assertEqualsWithDelta(111195.0, $dist1Deg, 500.0);
    }

    // ─── 6. Derived Fill-Down & Blank Posko (SECTION PIC) ─────────────────

    public function test_derived_fill_down_preserves_raw_in_database(): void
    {
        // Create 3 rows with visual grouping: Row 1 has feeder, Row 2 & 3 have NULL
        $row1 = SectionPicSourceRow::create([
            'penyulang_raw' => 'JATILAN',
            'pemutus_raw' => 'GI SRIBAWONO P. JATILAN',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $row2 = SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'RECLOSER PERUMNAS',
            'posko_pic_raw' => 'PUGUNG',
            'source_row_number' => 3,
        ]);

        $row3 = SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'LBSM KARYA TANI',
            'posko_pic_raw' => 'PUGUNG',
            'source_row_number' => 4,
        ]);

        $result = $this->command->auditSectionPic();

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(['JATILAN'], $result['distinct_feeders']);
        $this->assertSame('JATILAN', $result['derived_rows'][0]['derived_feeder']);
        $this->assertSame('JATILAN', $result['derived_rows'][1]['derived_feeder']);
        $this->assertSame('JATILAN', $result['derived_rows'][2]['derived_feeder']);

        // Database RAW values must REMAIN UNTOUCHED (null remains null)
        $row1->refresh();
        $row2->refresh();
        $row3->refresh();

        $this->assertSame('JATILAN', $row1->penyulang_raw);
        $this->assertNull($row2->penyulang_raw);
        $this->assertNull($row3->penyulang_raw);
    }

    public function test_first_row_null_feeder_does_not_inherit_fake_value(): void
    {
        // Row 1 starts with NULL feeder
        $row1 = SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'UNASSIGNED EQUIPMENT',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        $row2 = SectionPicSourceRow::create([
            'penyulang_raw' => 'JATILAN',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 3,
        ]);

        $result = $this->command->auditSectionPic();

        $this->assertNull($result['derived_rows'][0]['derived_feeder']);
        $this->assertSame('JATILAN', $result['derived_rows'][1]['derived_feeder']);
        $this->assertCount(1, $result['unresolved_rows']);
        $this->assertSame('UNASSIGNED EQUIPMENT', $result['unresolved_rows'][0]['raw_pemutus']);
    }

    public function test_blank_posko_pic_is_recorded_in_review(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => '', // Blank posko
            'source_row_number' => 2,
        ]);

        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'LBSM KARYA TANI',
            'posko_pic_raw' => null, // Null posko
            'source_row_number' => 3,
        ]);

        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'FCO JAIPONG 01',
            'posko_pic_raw' => 'SRIBHAWONO', // Valid posko
            'source_row_number' => 4,
        ]);

        $result = $this->command->auditSectionPic();

        $this->assertCount(2, $result['blank_posko_rows']);
        $this->assertSame(2, $result['blank_posko_rows'][0]['row_number']);
        $this->assertSame('REC JABO', $result['blank_posko_rows'][0]['raw_pemutus']);
        $this->assertSame(3, $result['blank_posko_rows'][1]['row_number']);
    }

    // ─── 7. Matching, Type Mismatch & Unique Entity Count ────────────────

    public function test_same_canonical_name_different_feeder_triggers_conflict(): void
    {
        // Section PIC has LBSM ZAPTOR on JATILAN
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JATILAN',
            'pemutus_raw' => 'LBSM ZAPTOR',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        // Keypoint has LBSM ZAPTOR on BRAJASAKTI
        KeypointSourceRow::create([
            'penyulang_raw' => 'BRAJASAKTI',
            'jenis_raw' => 'LBSM',
            'nama_raw' => 'LBSM ZAPTOR',
            'koordinat_raw' => '-5.21099, 105.46007',
            'source_row_number' => 2,
        ]);

        $secData = $this->command->auditSectionPic();
        $kpData = $this->command->auditKeypoint();

        $matchingResult = $this->command->auditNameMatching($secData, $kpData);

        $this->assertCount(0, $matchingResult['matched']);
        $this->assertCount(1, $matchingResult['conflicts']);
        $this->assertSame('LBSM ZAPTOR', $matchingResult['conflicts'][0]['canonical_name']);
        $this->assertSame('JATILAN', $matchingResult['conflicts'][0]['sec_feeder']);
        $this->assertSame('BRAJASAKTI', $matchingResult['conflicts'][0]['kp_feeders']);
    }

    public function test_same_feeder_same_canonical_name_different_type_triggers_type_mismatch(): void
    {
        // Section PIC has LBSM KARYA TANI on JAIPONG (expected type: LBSM)
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'LBSM KARYA TANI',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        // Keypoint has LBSM KARYA TANI on JAIPONG but with jenis FCO
        KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'FCO',
            'nama_raw' => 'LBSM KARYA TANI',
            'koordinat_raw' => '-5.21099, 105.46007',
            'source_row_number' => 2,
        ]);

        $secData = $this->command->auditSectionPic();
        $kpData = $this->command->auditKeypoint();

        $matchingResult = $this->command->auditNameMatching($secData, $kpData);

        // Must NOT be classified as MATCHED
        $this->assertCount(0, $matchingResult['matched']);
        $this->assertSame(0, $matchingResult['unique_entity_matched_count']);

        // Must be classified as TYPE_MISMATCH
        $this->assertCount(1, $matchingResult['type_mismatches']);
        $this->assertSame('LBSM KARYA TANI', $matchingResult['type_mismatches'][0]['canonical_name']);
        $this->assertSame('LBSM', $matchingResult['type_mismatches'][0]['sec_expected_type']);
        $this->assertSame('FCO', $matchingResult['type_mismatches'][0]['kp_actual_type']);
    }

    public function test_duplicate_aliases_do_not_double_count_unique_entity(): void
    {
        // Section PIC has 2 rows with naming variants for the same physical equipment:
        // Row 2: REC JABO
        // Row 3: RECLOSER JABO
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'RECLOSER JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 3,
        ]);

        // Keypoint has 1 row: RECLOSER JABO
        KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'RECLOSER',
            'nama_raw' => 'RECLOSER JABO',
            'koordinat_raw' => '-5.21099, 105.46007',
            'source_row_number' => 2,
        ]);

        $secData = $this->command->auditSectionPic();
        $kpData = $this->command->auditKeypoint();

        $matchingResult = $this->command->auditNameMatching($secData, $kpData);

        // Raw match count = 2 (two section pic rows matched)
        $this->assertSame(2, $matchingResult['raw_matched_count']);

        // Unique entity match count MUST be 1 (single unique canonical entity JAIPONG|RECLOSER JABO)
        $this->assertSame(1, $matchingResult['unique_entity_matched_count']);
        $this->assertSame(['JAIPONG|RECLOSER JABO'], $matchingResult['unique_matched_entities']);
    }

    // ─── 8. Keypoint to Asset Proximity & Outliers ───────────────────────

    public function test_proximity_finds_nearest_asset_on_same_feeder(): void
    {
        // Asset 1: Far away (approx 1 km)
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-01',
            'nama_aset_raw' => 'Tiang Jauh',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.22000',
            'longitude_raw' => '105.46000',
            'source_row_number' => 2,
        ]);

        // Asset 2: Very close (approx 2 meters)
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-02',
            'nama_aset_raw' => 'Tiang Dekat',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.21099',
            'longitude_raw' => '105.46009',
            'source_row_number' => 3,
        ]);

        // Keypoint on JAIPONG
        KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'RECLOSER',
            'nama_raw' => 'REC JABO',
            'koordinat_raw' => '-5.21099, 105.46007',
            'source_row_number' => 2,
        ]);

        $kpData = $this->command->auditKeypoint();
        $proximity = $this->command->auditKeypointAssetProximity($kpData);

        $this->assertSame(2, $proximity['total_assets_indexed']);
        $this->assertCount(1, $proximity['results']);
        $this->assertSame('AST-02', $proximity['results'][0]['nearest_id_aset']);
        $this->assertSame('Tiang Dekat', $proximity['results'][0]['nearest_nama_aset']);
        $this->assertLessThan(5.0, $proximity['results'][0]['distance_meter']);
    }

    public function test_proximity_outlier_greater_than_50m_is_recorded_in_review(): void
    {
        // Asset far away (> 50m, e.g. ~500m)
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-FAR',
            'nama_aset_raw' => 'Tiang Jauh Sekali',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.21500',
            'longitude_raw' => '105.46007',
            'source_row_number' => 2,
        ]);

        // Keypoint on JAIPONG
        KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'RECLOSER',
            'nama_raw' => 'REC JABO',
            'koordinat_raw' => '-5.21099, 105.46007',
            'source_row_number' => 2,
        ]);

        $kpData = $this->command->auditKeypoint();
        $proximity = $this->command->auditKeypointAssetProximity($kpData);

        $this->assertSame(1, $proximity['distribution']['gt_50m']);
        $this->assertCount(1, $proximity['outliers']);
        $this->assertSame('AST-FAR', $proximity['outliers'][0]['nearest_id_aset']);
        $this->assertGreaterThan(50.0, $proximity['outliers'][0]['distance_meter']);
    }

    // ─── 9. Database Immobility (Command Does Not Mutate Data) ───────────

    public function test_audit_execution_does_not_mutate_staging_rows(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 2,
        ]);

        KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'RECLOSER',
            'nama_raw' => 'REC JABO',
            'koordinat_raw' => '-5.21099, 105.46007',
            'source_row_number' => 2,
        ]);

        AssetSourceRow::create([
            'id_aset_raw' => 'AST-01',
            'nama_aset_raw' => 'Tiang 01',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.21099',
            'longitude_raw' => '105.46007',
            'source_row_number' => 2,
        ]);

        $secCountBefore = SectionPicSourceRow::count();
        $kpCountBefore = KeypointSourceRow::count();
        $assetCountBefore = AssetSourceRow::count();

        // Run audit in test environment
        $this->artisan('topology:audit')->assertExitCode(0);

        $this->assertSame($secCountBefore, SectionPicSourceRow::count());
        $this->assertSame($kpCountBefore, KeypointSourceRow::count());
        $this->assertSame($assetCountBefore, AssetSourceRow::count());
    }
}
