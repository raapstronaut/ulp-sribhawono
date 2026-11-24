<?php

namespace Tests\Feature\Operations;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Jalur website v1, hanya baca: antrean ACC dan katalog ASSET_RAW.
 * Tidak mengaktifkan generator maupun menyentuh database operasional.
 */
class WebsiteDualTrackReadOnlyTest extends OperationsTestCase
{
    public function test_acc_queue_lists_only_approved_unlinked_findings_without_creating_wo(): void
    {
        $inspection = Inspection::create([
            'code' => 'INS-TEST-QUEUE',
            'field_team_id' => $this->inspeksiTeam->id,
            'penyulang_id' => $this->penyulang->id,
            'section_name' => 'SEC 1',
            'visited_at' => now(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
        ]);

        InspectionFinding::create([
            'inspection_id' => $inspection->id,
            'finding_code' => 'POHON_DEKAT',
            'description' => 'Pohon dekat jaringan dan perlu penanganan',
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ]);
        InspectionFinding::create([
            'inspection_id' => $inspection->id,
            'finding_code' => 'PENDING_EXAMPLE',
            'description' => 'Temuan belum divalidasi',
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_MENUNGGU,
        ]);

        $before = WorkOrder::query()->count();
        $this->actingAs($this->admin)->get(route('admin.inspeksi.queue'))
            ->assertOk()
            ->assertSee('Pohon dekat jaringan dan perlu penanganan')
            ->assertDontSee('Temuan belum divalidasi')
            ->assertSee('Perlu pemeriksaan rencana ROW');
        $this->assertSame($before, WorkOrder::query()->count());
    }

    public function test_asset_staging_catalog_details_are_read_only_and_do_not_invent_history(): void
    {
        $id = DB::table('asset_source_rows')->insertGetId([
            'id_aset_raw' => 'ASSET-TEST-001',
            'nama_aset_raw' => 'TIANG UJI',
            'jenis_aset_raw' => 'TIANG',
            'penyulang_raw' => 'REOG',
            'latitude_raw' => '-5.2',
            'longitude_raw' => '105.7',
            'status_raw' => 'AKTIF',
            'source_row_number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.aset.index', ['q' => 'ASSET-TEST']))
            ->assertOk()->assertSee('ASSET-TEST-001')->assertSee('TIANG UJI');
        $this->actingAs($this->admin)->get(route('admin.aset.show', $id))
            ->assertOk()->assertSee('TIANG UJI')->assertSee('Histori Pemasangan')
            ->assertSee('belum diaktifkan')->assertSee('Belum tersedia');
        $this->assertSame(1, DB::table('asset_source_rows')->count());
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_new_read_only_pages_are_restricted_to_admin(): void
    {
        $this->get(route('admin.aset.index'))->assertRedirect(route('login'));
        $this->get(route('admin.inspeksi.queue'))->assertRedirect(route('login'));

        $field = $this->fieldAccount($this->rowTeam, 'FIELD_READONLY_DENIED');
        $this->actingAs($field)->get(route('admin.aset.index'))->assertForbidden();
        $this->actingAs($field)->get(route('admin.inspeksi.queue'))->assertForbidden();
    }
}
