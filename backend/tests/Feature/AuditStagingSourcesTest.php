<?php

namespace Tests\Feature;

use App\Models\AssetSourceRow;
use App\Models\VegetasiSourceRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditStagingSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_staging_command_is_registered(): void
    {
        $this->artisan('sources:audit-staging', ['--help' => true])
            ->assertExitCode(0);
    }

    public function test_audit_staging_command_runs_on_empty_tables(): void
    {
        $this->artisan('sources:audit-staging')
            ->expectsOutputToContain('AUDIT DATA SOURCE STAGING')
            ->expectsOutputToContain('Tabel asset_source_rows kosong')
            ->expectsOutputToContain('Tabel vegetasi_source_rows kosong')
            ->assertExitCode(0);
    }

    public function test_audit_staging_command_audits_metrics_and_cross_reference(): void
    {
        // ─── 1. Setup Asset Staging Data ───
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-001',
            'nama_aset_raw' => 'TIANG A',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.08388',
            'longitude_raw' => '105.63628',
            'source_row_number' => 2,
        ]);
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-002',
            'nama_aset_raw' => 'TIANG B',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => 'invalid_lat',
            'longitude_raw' => '105.63628',
            'source_row_number' => 3,
        ]);
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-001', // Duplicate ID
            'nama_aset_raw' => 'TIANG DUPLICATE',
            'penyulang_raw' => 'JAIPONG',
            'source_row_number' => 4,
        ]);
        AssetSourceRow::create([
            'id_aset_raw' => '', // Blank ID
            'nama_aset_raw' => '', // Blank Nama
            'source_row_number' => 5,
        ]);

        // ─── 2. Setup Vegetasi Staging Data ───
        // Match: TIANG A + JAIPONG
        VegetasiSourceRow::create([
            'id_pohon_raw' => 'P-001',
            'nama_tiang_raw' => 'TIANG A',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.08388',
            'longitude_raw' => '105.63628',
            'source_row_number' => 2,
        ]);
        // Unmatched: TIANG UNRESOLVED
        VegetasiSourceRow::create([
            'id_pohon_raw' => 'P-002',
            'nama_tiang_raw' => 'TIANG UNRESOLVED',
            'penyulang_raw' => 'JAIPONG',
            'latitude_raw' => '-5.08388',
            'longitude_raw' => '999.99', // Invalid Longitude
            'source_row_number' => 3,
        ]);
        // Unmatched: Feeder mismatch (TIANG A but feeder OTHER)
        VegetasiSourceRow::create([
            'id_pohon_raw' => 'P-003',
            'nama_tiang_raw' => 'TIANG A',
            'penyulang_raw' => 'OTHER_FEEDER',
            'source_row_number' => 4,
        ]);
        // Blank ID pohon
        VegetasiSourceRow::create([
            'id_pohon_raw' => null,
            'nama_tiang_raw' => 'TIANG A',
            'penyulang_raw' => 'JAIPONG',
            'source_row_number' => 5,
        ]);

        $this->artisan('sources:audit-staging')
            ->expectsOutputToContain('AUDIT: asset_source_rows')
            ->expectsOutputToContain('AUDIT: vegetasi_source_rows')
            ->expectsOutputToContain('CROSS-REFERENCE: VEGETASI → ASET')
            ->expectsOutputToContain('Vegetasi MATCH (Nama Tiang + Penyulang)')
            ->expectsOutputToContain('TIANG UNRESOLVED')
            ->assertExitCode(0);
    }

    public function test_audit_staging_command_is_read_only(): void
    {
        AssetSourceRow::create([
            'id_aset_raw' => 'AST-001',
            'nama_aset_raw' => 'TIANG A',
            'penyulang_raw' => 'JAIPONG',
            'source_row_number' => 2,
        ]);

        VegetasiSourceRow::create([
            'id_pohon_raw' => 'P-001',
            'nama_tiang_raw' => 'TIANG A',
            'penyulang_raw' => 'JAIPONG',
            'source_row_number' => 2,
        ]);

        $assetCountBefore = AssetSourceRow::count();
        $vegetasiCountBefore = VegetasiSourceRow::count();

        $this->artisan('sources:audit-staging')
            ->assertExitCode(0);

        $this->assertSame($assetCountBefore, AssetSourceRow::count());
        $this->assertSame($vegetasiCountBefore, VegetasiSourceRow::count());
    }
}
