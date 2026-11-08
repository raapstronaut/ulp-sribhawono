<?php

namespace Tests\Feature\Admin\Foundation;

use App\Enums\WorkOrderStatus;

class WorkOrderRedesignTest extends FoundationTestCase
{
    public function test_monitor_shows_four_agreed_kpis_and_shift_scopes(): void
    {
        $this->makeWorkOrder(['status' => WorkOrderStatus::Dikerjakan]);
        $this->makeWorkOrder(['status' => WorkOrderStatus::MenungguVerifikasi, 'shift_code' => 'B']);

        $this->actingAs($this->admin)->get(route('admin.work-orders.index'))
            ->assertOk()
            ->assertSee('WO Shift Ini')
            ->assertSee('Sedang Dikerjakan')
            ->assertSee('Perlu Verifikasi')
            ->assertSee('Terlambat')
            ->assertSee('Progres per POSKO')
            ->assertSee('Daftar Work Order')
            ->assertSee('Antrean laporan seluruh shift')
            ->assertSee('Tanggal operasional');
    }

    public function test_manual_create_has_staged_flow_without_losing_existing_route(): void
    {
        $this->actingAs($this->admin)->get(route('admin.work-orders.create'))
            ->assertOk()
            ->assertSee('Langkah 1')
            ->assertSee('Pilih kandidat APKUK')
            ->assertSee('Langkah 2')
            ->assertSee('Atur penugasan')
            ->assertSee('Langkah 3')
            ->assertSee('Konfirmasi')
            ->assertSee('Lihat Data Sumber APKUK');
    }

    public function test_apkuk_detail_has_quick_navigation_and_preserves_audit_and_forms(): void
    {
        $wo = $this->makeWorkOrder();
        $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('Navigasi detail pekerjaan')
            ->assertSee('Status pekerjaan')
            ->assertSee('POSKO penanggung jawab')
            ->assertSee('id="penugasan"', false)
            ->assertSee('id="laporan"', false)
            ->assertSee('id="riwayat"', false)
            ->assertSee('Riwayat audit');
    }
}
