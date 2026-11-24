<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;

/** UI-only: tab dapat diakses tanpa mengubah WO dan keputusan inspeksi. */
class WebsiteWorkDetailTabsV23Test extends OperationsTestCase
{
    public function test_posko_detail_has_interactive_tabs_and_keeps_actions_and_audit(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('data-pln-detail-root', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('data-pln-detail-tab="penugasan"', false)
            ->assertSee('data-pln-detail-tab="laporan"', false)
            ->assertSee('data-pln-detail-tab="riwayat"', false)
            ->assertSee('data-pln-detail-tab="aksi"', false)
            ->assertSee('data-pln-detail-panel="laporan"', false)
            ->assertSee('data-pln-detail-panel="riwayat"', false)
            ->assertSee('data-pln-detail-panel="aksi"', false)
            ->assertSee('Tandai mulai dikerjakan')
            ->assertSee('Riwayat audit');
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_row_detail_uses_same_tab_shell_without_posko_specific_actions(): void
    {
        $wo = WorkOrder::create([
            'code' => 'WO-ROW-DETAIL-V23',
            'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_RABAS,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan,
            'penyulang_id' => $this->penyulang->id,
            'work_date' => '2026-10-28',
        ]);

        $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('Tab detail work order')
            ->assertSee('data-pln-detail-tab="lokasi"', false)
            ->assertSee('data-pln-detail-tab="riwayat"', false)
            ->assertSee('data-pln-detail-panel="riwayat"', false);
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_inspection_detail_tabs_keep_approval_controls_and_findings(): void
    {
        $inspection = Inspection::create([
            'code' => 'INS-V23-TABS',
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
            'description' => 'Pohon dekat jaringan untuk uji tampilan',
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_MENUNGGU,
        ]);
        $this->actingAs($this->admin)->get(route('admin.inspeksi.show', $inspection))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('data-pln-detail-tab="kunjungan"', false)
            ->assertSee('data-pln-detail-tab="temuan"', false)
            ->assertSee('data-pln-detail-panel="temuan"', false)
            ->assertSee('Pohon dekat jaringan untuk uji tampilan')
            ->assertSee('ACC temuan')
            ->assertSee('Tolak temuan');
        $this->assertSame(0, WorkOrder::query()->count());
    }
}
