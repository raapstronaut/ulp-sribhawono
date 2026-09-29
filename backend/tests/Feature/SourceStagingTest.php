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
            'penyulang_raw', 'section_id_raw', 'posko_id_raw',
            'latitude_raw', 'longitude_raw', 'foto_url_raw', 'status_raw',
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
            'section_id_raw' => 'SEC-01',
            'posko_id_raw' => 'POSKO-01',
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
            'id', 'id_pohon_raw', 'nama_pohon_raw', 'penyulang_raw',
            'id_aset_raw', 'nama_tiang_raw', 'jenis_pohon_raw',
            'latitude_raw', 'longitude_raw', 'jarak_pohon_raw',
            'kondisi_raw', 'tanggal_survey_raw', 'section_id_raw',
            'keterangan_m_raw', 'status_raw', 'tanggal_inspeksi_terakhir_raw',
            'tanggal_row_terakhir_raw', 'jenis_row_terakhir_raw',
            'kondisi_terakhir_agustus_raw', 'foto_ref_raw', 'keterangan_t_raw',
            'source_row_number', 'imported_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_vegetasi_source_row_handles_duplicate_keterangan_columns(): void
    {
        $row = VegetasiSourceRow::create([
            'id_pohon_raw' => 'P-001',
            'keterangan_m_raw' => 'Keterangan kolom M',
            'keterangan_t_raw' => 'Keterangan kolom T',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $row->refresh();

        $this->assertSame('Keterangan kolom M', $row->keterangan_m_raw);
        $this->assertSame('Keterangan kolom T', $row->keterangan_t_raw);
    }

    public function test_vegetasi_source_row_can_be_created(): void
    {
        VegetasiSourceRow::create([
            'id_pohon_raw' => 'P-001',
            'nama_pohon_raw' => 'Mangga',
            'jenis_pohon_raw' => 'BUAH',
            'jarak_pohon_raw' => '0,47',
            'source_row_number' => 2,
            'imported_at' => now(),
        ]);

        $this->assertDatabaseHas('vegetasi_source_rows', [
            'id_pohon_raw' => 'P-001',
            'jarak_pohon_raw' => '0,47',
        ]);
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
