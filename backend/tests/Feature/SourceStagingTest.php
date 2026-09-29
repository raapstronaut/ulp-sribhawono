<?php

namespace Tests\Feature;

use App\Models\AssetSourceRow;
use App\Models\GangguanSourceRow;
use App\Models\VegetasiSourceRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // ─── Gangguan Source Rows ──────────────────────────────────────

    public function test_gangguan_source_rows_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('gangguan_source_rows'));
        $this->assertTrue(Schema::hasColumns('gangguan_source_rows', [
            'id', 'id_gangguan_raw', 'penyulang_raw', 'peralatan_raw',
            'ulp_raw', 'tanggal_kejadian_raw', 'kode_gangguan_raw',
            'uraian_penyebab_raw', 'penyebab_raw', 'lepas_raw', 'masuk_raw',
            'beban_amp_raw', 'rele_kerja_raw', 'arus_gangguan_amp_raw',
            'lama_padam_menit_raw', 'ens_kwh_raw', 'sifat_gangguan_raw',
            'id_aset_raw', 'section_id_raw', 'posko_id_raw',
            'latitude_raw', 'longitude_raw', 'jenis_hewan_raw',
            'foto_ref_raw', 'sumber_data_raw',
            'source_row_number', 'imported_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_gangguan_source_row_can_be_created(): void
    {
        GangguanSourceRow::create([
            'id_gangguan_raw' => 'G-001',
            'penyulang_raw' => 'JAIPONG',
            'tanggal_kejadian_raw' => '01/15/2026',
            'lama_padam_menit_raw' => '4,61',
            'ens_kwh_raw' => '0,47',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('gangguan_source_rows', [
            'id_gangguan_raw' => 'G-001',
            'lama_padam_menit_raw' => '4,61',
            'ens_kwh_raw' => '0,47',
        ]);
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

        $gangguan = GangguanSourceRow::create([
            'source_row_number' => 4,
            'imported_at' => now(),
        ]);
        $this->assertNotNull($gangguan->id);
    }

    // ─── Command Registration ─────────────────────────────────────

    public function test_sources_import_command_is_registered(): void
    {
        $this->artisan('sources:import', ['--help' => true])
            ->assertExitCode(0);
    }
}
