<?php

namespace Tests\Feature;

use App\Models\AssetSourceRow;
use App\Models\KeypointSourceRow;
use App\Models\SectionPicSourceRow;
use App\Models\VegetasiSourceRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SourceStagingTest extends TestCase
{
    use RefreshDatabase;

    // ─── Asset Source Rows ─────────────────────────────────────────

    public function test_asset_source_rows_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('asset_source_rows'));
        $this->assertTrue(Schema::hasColumns('asset_source_rows', [
            'id', 'id_aset_raw', 'nama_aset_raw', 'jenis_aset_raw',
            'penyulang_raw', 'latitude_raw', 'longitude_raw', 'status_raw',
            'source_row_number', 'imported_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_asset_source_row_can_be_created(): void
    {
        $row = AssetSourceRow::create([
            'id_aset_raw' => 'TG-001',
            'nama_aset_raw' => 'Tiang Test',
            'jenis_aset_raw' => 'TIANG',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.17318',
            'longitude_raw' => '105.84721',
            'status_raw' => 'AKTIF',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('asset_source_rows', [
            'id_aset_raw' => 'TG-001',
            'penyulang_raw' => 'JAIPONG',
        ]);
    }

    public function test_asset_source_row_preserves_decimal_comma_values(): void
    {
        $row = AssetSourceRow::create([
            'latitude_raw' => '-5,17318',
            'longitude_raw' => '105,84721',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $row->refresh();

        $this->assertSame('-5,17318', $row->latitude_raw);
        $this->assertSame('105,84721', $row->longitude_raw);
    }

    // ─── Vegetasi Source Rows ──────────────────────────────────────

    public function test_vegetasi_source_rows_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('vegetasi_source_rows'));
        $this->assertTrue(Schema::hasColumns('vegetasi_source_rows', [
            'id', 'no_raw', 'nama_pohon_raw', 'up3_raw', 'ulp_raw',
            'penyulang_raw', 'id_jtm_raw', 'id_tiang_raw', 'nama_tiang_raw',
            'jenis_pohon_raw', 'pertumbuhan_pohon_raw', 'jarak_aman_raw',
            'jarak_bahaya_raw', 'kondisi_raw', 'section_raw', 'eam_raw',
            'latitude_raw', 'longitude_raw', 'jarak_pohon_raw',
            'jarak_pohon_saat_ini_raw', 'izin_padam_raw', 'izin_tebang_bawah_raw',
            'tanggal_update_raw', 'tanggal_periksa_raw',
            'tanggal_perkiraan_bahaya_raw', 'user_pembuat_raw',
            'user_update_raw', 'keterangan_raw', 'id_pohon_raw',
            'source_row_number', 'imported_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_vegetasi_source_row_can_be_created(): void
    {
        VegetasiSourceRow::create([
            'no_raw' => '1',
            'nama_pohon_raw' => 'Kelapa',
            'up3_raw' => 'UP3 METRO',
            'ulp_raw' => 'ULP SRIBHAWONO',
            'penyulang_raw' => 'JAIPONG',
            'id_jtm_raw' => 'JTM-01',
            'id_tiang_raw' => 'T-01',
            'nama_tiang_raw' => 'Tiang 01',
            'jenis_pohon_raw' => 'KELAPA',
            'pertumbuhan_pohon_raw' => 'SEDANG',
            'jarak_aman_raw' => '2.5',
            'jarak_bahaya_raw' => '1.0',
            'kondisi_raw' => 'BAHAYA',
            'section_raw' => 'SEC-01',
            'eam_raw' => 'EAM-100',
            'latitude_raw' => '-5.08388',
            'longitude_raw' => '105.63628',
            'jarak_pohon_raw' => '0,47',
            'jarak_pohon_saat_ini_raw' => '0.5',
            'izin_padam_raw' => 'YA',
            'izin_tebang_bawah_raw' => 'TIDAK',
            'tanggal_update_raw' => '2026-09-01',
            'tanggal_periksa_raw' => '2026-09-01',
            'tanggal_perkiraan_bahaya_raw' => '2026-10-01',
            'user_pembuat_raw' => 'SURVEYOR1',
            'user_update_raw' => 'ADMIN',
            'keterangan_raw' => 'Dekat kawat TM',
            'id_pohon_raw' => 'P-001',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('vegetasi_source_rows', [
            'id_pohon_raw' => 'P-001',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.08388',
            'longitude_raw' => '105.63628',
            'jarak_pohon_raw' => '0,47',
        ]);
    }

    public function test_vegetasi_source_row_preserves_raw_coordinates_and_distances(): void
    {
        $row = VegetasiSourceRow::create([
            'latitude_raw' => '-5.08388',
            'longitude_raw' => '105.63628',
            'jarak_pohon_raw' => '0,47',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $row->refresh();

        $this->assertSame('-5.08388', $row->latitude_raw);
        $this->assertSame('105.63628', $row->longitude_raw);
        $this->assertSame('0,47', $row->jarak_pohon_raw);
    }

    // ─── Gangguan (sinkron PLN Pusat) ──────────────────────────────
    // gangguan_source_rows sudah digantikan tabel `gangguans` yang diisi gangguan:sync.

    public function test_gangguan_source_rows_table_is_replaced_by_gangguans(): void
    {
        $this->assertFalse(Schema::hasTable('gangguan_source_rows'));
        $this->assertTrue(Schema::hasTable('gangguans'));
        $this->assertTrue(Schema::hasColumns('gangguans', [
            'id_gangguan', 'tanggal_kejadian', 'penyulang', 'ulp', 'peralatan',
            'kode_gangguan', 'sifat_gangguan', 'penyebab', 'uraian_penyebab',
            'no_tiang', 'keypoint_pusat', 'lepas', 'masuk', 'lama_padam_menit',
            'ens_kwh', 'beban_amp', 'rele_kerja', 'arus_gangguan_amp',
            'foto_gangguan_url', 'sumber_data', 'last_synced_at',
            'created_at', 'updated_at',
        ]));
    }

    public function test_gangguan_row_can_be_created_with_minimal_data(): void
    {
        DB::table('gangguans')->insert([
            'id_gangguan' => 'G-001',
            'penyulang' => 'JAIPONG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('gangguans', [
            'id_gangguan' => 'G-001',
            'penyulang' => 'JAIPONG',
            'sumber_data' => 'PLN_PUSAT',
        ]);
    }

    // ─── Section PIC Source Rows ──────────────────────────────────

    public function test_section_pic_source_rows_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('section_pic_source_rows'));
        $this->assertTrue(Schema::hasColumns('section_pic_source_rows', [
            'id', 'penyulang_raw', 'pemutus_raw', 'posko_pic_raw',
            'source_row_number', 'imported_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_section_pic_source_row_can_be_created(): void
    {
        SectionPicSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 3,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('section_pic_source_rows', [
            'penyulang_raw' => 'JAIPONG',
            'pemutus_raw' => 'REC JABO',
            'posko_pic_raw' => 'SRIBHAWONO',
        ]);
    }

    public function test_section_pic_source_row_allows_null_penyulang(): void
    {
        // Source menggunakan grouping visual: penyulang ditulis sekali,
        // baris keypoint berikutnya sengaja kosong. Jangan fill-down.
        $row = SectionPicSourceRow::create([
            'penyulang_raw' => null,
            'pemutus_raw' => 'LBSM KARYA TANI',
            'posko_pic_raw' => 'SRIBHAWONO',
            'source_row_number' => 4,
            'imported_at' => now(),
        ]);

        $row->refresh();

        $this->assertNull($row->penyulang_raw);
        $this->assertSame('LBSM KARYA TANI', $row->pemutus_raw);
        $this->assertSame('SRIBHAWONO', $row->posko_pic_raw);
    }

    public function test_section_pic_preserves_raw_naming_variants(): void
    {
        // Berbagai varian nama harus disimpan apa adanya tanpa canonicalization.
        $variants = ['REC JABO', 'REC-JABO', 'RECLOSER JABO', 'BRAJADEWA', 'BRAJA DEWA'];

        foreach ($variants as $i => $variant) {
            SectionPicSourceRow::create([
                'pemutus_raw' => $variant,
                'source_row_number' => $i + 2,
                'imported_at' => now(),
            ]);
        }

        foreach ($variants as $variant) {
            $this->assertDatabaseHas('section_pic_source_rows', [
                'pemutus_raw' => $variant,
            ]);
        }
    }

    // ─── Keypoint Source Rows ─────────────────────────────────────

    public function test_keypoint_source_rows_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('keypoint_source_rows'));
        $this->assertTrue(Schema::hasColumns('keypoint_source_rows', [
            'id', 'penyulang_raw', 'jenis_raw', 'nama_raw',
            'koordinat_raw', 'note_raw',
            'source_row_number', 'imported_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_keypoint_source_row_can_be_created(): void
    {
        KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'FCO',
            'nama_raw' => 'FCO JAIPONG 01',
            'koordinat_raw' => '-5.21099, 105.46007',
            'note_raw' => 'Dekat jalan utama',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('keypoint_source_rows', [
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'FCO',
            'nama_raw' => 'FCO JAIPONG 01',
        ]);
    }

    public function test_keypoint_source_row_preserves_coordinate_string(): void
    {
        // Koordinat disimpan sebagai raw string, tidak dipecah menjadi lat/long.
        $coordString = '-5.21099, 105.46007';

        $row = KeypointSourceRow::create([
            'koordinat_raw' => $coordString,
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $row->refresh();

        $this->assertSame($coordString, $row->koordinat_raw);
    }

    public function test_keypoint_source_row_allows_null_note(): void
    {
        $row = KeypointSourceRow::create([
            'penyulang_raw' => 'JAIPONG',
            'jenis_raw' => 'RECLOSER',
            'nama_raw' => 'REC JABO',
            'koordinat_raw' => '-5.21099, 105.46007',
            'note_raw' => null,
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $row->refresh();

        $this->assertNull($row->note_raw);
    }

    public function test_keypoint_preserves_raw_jenis_variants(): void
    {
        $types = ['FCO', 'LBSM', 'RECLOSER'];

        foreach ($types as $i => $type) {
            KeypointSourceRow::create([
                'jenis_raw' => $type,
                'source_row_number' => $i + 2,
                'imported_at' => now(),
            ]);
        }

        foreach ($types as $type) {
            $this->assertDatabaseHas('keypoint_source_rows', [
                'jenis_raw' => $type,
            ]);
        }
    }

    // ─── Shared: Nullable / Minimal ───────────────────────────────

    public function test_all_staging_rows_accept_minimal_data(): void
    {
        $asset = AssetSourceRow::create([
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);
        $this->assertNotNull($asset->id);

        $vegetasi = VegetasiSourceRow::create([
            'source_row_number' => 3,
            'imported_at' => now(),
        ]);
        $this->assertNotNull($vegetasi->id);

        $sectionPic = SectionPicSourceRow::create([
            'source_row_number' => 5,
            'imported_at' => now(),
        ]);
        $this->assertNotNull($sectionPic->id);

        $keypoint = KeypointSourceRow::create([
            'source_row_number' => 6,
            'imported_at' => now(),
        ]);
        $this->assertNotNull($keypoint->id);
    }

    // ─── Command Registration ─────────────────────────────────────

    public function test_sources_import_command_is_registered(): void
    {
        $this->artisan('sources:import', ['--help' => true])
            ->assertExitCode(0);
    }
}
