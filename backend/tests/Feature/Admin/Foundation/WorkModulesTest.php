<?php

namespace Tests\Feature\Admin\Foundation;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Models\WorkOrderStatusLog;

class WorkModulesTest extends FoundationTestCase
{
    private Posko $pugung;

    private Posko $jabung;

    private Penyulang $reog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pugung = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $this->jabung = Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        $this->reog = Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);
        $this->pugung->penyulangs()->attach($this->reog->id);
    }

    public function test_pasila_monitor_shows_real_shift_context_and_marks_pasila_domain_unavailable(): void
    {
        $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'status' => WorkOrderStatus::Selesai, 'completed_at' => now()]);
        $active = $this->makeWorkOrder(['posko_id' => $this->pugung->id]);
        $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'shift_code' => 'B']);
        $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'status' => WorkOrderStatus::Dibatalkan]);
        WorkOrderReschedule::create([
            'work_order_id' => $active->id,
            'reason_code' => 'CUACA',
            'note' => 'Hujan deras',
            'from_work_date' => '2026-10-20',
            'proposed_work_date' => '2026-10-21',
            'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.pasila.index'))
            ->assertOk()
            ->assertSee('Domain PASILA belum dibangun')
            ->assertSee('Shift A · Pagi')
            ->assertSee('PUGUNG')
            ->assertSee('JABUNG')
            ->assertSeeInOrder(['PUGUNG', '1 / 6', '2 WO tercatat · 1 aktif', '1 menunggu'])
            ->assertSee('Buat PASILA')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee(route('admin.pasila.show', ['posko' => $this->pugung, 'work_date' => '2026-10-20', 'shift_code' => 'A']));
    }

    public function test_pasila_filters_by_posko_and_shift_and_ignores_invalid_dates(): void
    {
        $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'shift_code' => 'B']);

        $this->actingAs($this->admin)
            ->get(route('admin.pasila.index', ['posko_id' => $this->jabung->id, 'shift_code' => 'B', 'work_date' => '2026-02-31']))
            ->assertOk()
            ->assertSee('Shift B · Sore — 20/10/2026')
            ->assertSee('JABUNG')
            ->assertDontSee('2 WO tercatat');

        $this->actingAs($this->admin)
            ->get(route('admin.pasila.index', ['shift_code' => 'B']))
            ->assertOk()
            ->assertSee('1 WO tercatat · 1 aktif');
    }

    public function test_pasila_detail_lists_only_this_posko_shift_work_and_requires_pasila_for_p0(): void
    {
        $mine = $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'penyulang_id' => $this->reog->id, 'code' => 'WO-PUGUNG-A']);
        $this->makeWorkOrder(['posko_id' => $this->jabung->id, 'code' => 'WO-JABUNG-A']);
        $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'shift_code' => 'C', 'code' => 'WO-PUGUNG-C']);
        WorkOrderReschedule::create([
            'work_order_id' => $mine->id,
            'reason_code' => 'AKSES_TERHALANG',
            'note' => 'Jalan ditutup',
            'from_work_date' => '2026-10-20',
            'proposed_work_date' => '2026-10-21',
            'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);
        FieldTeam::create(['code' => 'POSKO-PUGUNG', 'name' => 'Tim Pugung', 'role' => 'POSKO', 'posko_id' => $this->pugung->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.pasila.show', ['posko' => $this->pugung, 'work_date' => '2026-10-20', 'shift_code' => 'A']))
            ->assertOk()
            ->assertSee('WO-PUGUNG-A')
            ->assertDontSee('WO-JABUNG-A')
            ->assertDontSee('WO-PUGUNG-C')
            ->assertSee('AKSES_TERHALANG')
            ->assertSee('Tim Pugung')
            ->assertSee('REOG')
            ->assertSee('Buat P0 dari PASILA')
            ->assertSee('P0 wajib dibuat dari sesi PASILA yang valid')
            ->assertSee('Sesi PASILA &amp; Section', false);
    }

    public function test_pasila_detail_returns_404_for_unknown_posko(): void
    {
        $this->actingAs($this->admin)->get('/admin/pasila/not-a-uuid')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/pasila/9d3b1a4e-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_inspeksi_is_honest_skeleton_with_real_team_roster(): void
    {
        $team = FieldTeam::create(['code' => 'INSPEKSI-1', 'name' => 'Tim Inspeksi ULP', 'role' => 'INSPEKSI']);
        $team->personnel()->create(['name' => 'Petugas Satu']);
        FieldTeam::create(['code' => 'ROW-1', 'name' => 'Tim ROW ULP', 'role' => 'ROW']);

        $this->actingAs($this->admin)
            ->get(route('admin.inspeksi.index'))
            ->assertOk()
            ->assertSee('Domain Inspeksi belum dibangun')
            ->assertSee('Kerangka · domain belum dibangun')
            ->assertSee('Tim Inspeksi ULP')
            ->assertSee('1 petugas aktif')
            ->assertDontSee('Tim ROW ULP')
            ->assertSee('Belum ada temuan inspeksi.')
            ->assertSee('Filter belum aktif');

        $this->actingAs($this->admin)
            ->get(route('admin.inspeksi.show', 'INS-404'))
            ->assertNotFound()
            ->assertSee('Data inspeksi tidak ditemukan.')
            ->assertSee('INS-404');
    }

    public function test_row_page_lists_only_row_work_orders_with_filters_and_pagination(): void
    {
        foreach (range(1, 21) as $index) {
            $this->makeWorkOrder([
                'code' => sprintf('WO-ROW-%03d', $index),
                'team_type' => WorkOrder::TEAM_ROW,
                'work_type' => $index === 1 ? WorkOrder::TYPE_TEBANG : WorkOrder::TYPE_RABAS,
                'source' => WorkOrder::SOURCE_SYSTEM,
                'shift_code' => null,
                'created_at' => now()->subMinutes(30 - $index),
            ]);
        }
        $this->makeWorkOrder(['code' => 'WO-APKUK-ONLY']);

        $this->actingAs($this->admin)
            ->get(route('admin.row.index'))
            ->assertOk()
            ->assertSee('WO-ROW-021')
            ->assertDontSee('WO-APKUK-ONLY')
            ->assertSee('Hal. 1 / 2')
            ->assertSee('Otomatis sistem')
            ->assertSee('Buat WO ROW');

        $this->actingAs($this->admin)
            ->get(route('admin.row.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('WO-ROW-001');

        $this->actingAs($this->admin)
            ->get(route('admin.row.index', ['work_type' => WorkOrder::TYPE_TEBANG]))
            ->assertOk()
            ->assertSee('WO-ROW-001')
            ->assertDontSee('WO-ROW-002')
            ->assertSee('Reset');

        $this->actingAs($this->admin)
            ->get(route('admin.row.index', ['q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Belum ada WO ROW.');

        // Halaman di luar jangkauan dialihkan ke halaman terakhir dengan filter tetap terbawa.
        $location = $this->actingAs($this->admin)
            ->get(route('admin.row.index', ['page' => 9, 'work_type' => WorkOrder::TYPE_RABAS]))
            ->assertRedirect()
            ->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertEquals(['page' => '1', 'work_type' => WorkOrder::TYPE_RABAS], $query);

        // Tanpa data sama sekali: tetap tampil empty state, tidak dialihkan berulang.
        $this->actingAs($this->admin)
            ->get(route('admin.row.index', ['page' => 3, 'q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Belum ada WO ROW.');
    }

    public function test_harsus_page_lists_special_work_and_escalation_origin_without_p0(): void
    {
        $source = $this->makeWorkOrder(['code' => 'WO-POSKO-SUMBER', 'status' => WorkOrderStatus::SelesaiDiteruskan]);
        $this->makeWorkOrder([
            'code' => 'WO-HARSUS-CHILD',
            'team_type' => WorkOrder::TEAM_HARSUS,
            'work_type' => WorkOrder::TYPE_PEKERJAAN_KHUSUS,
            'source' => WorkOrder::SOURCE_ESCALATION,
            'parent_work_order_id' => $source->id,
            'shift_code' => null,
        ]);
        $this->makeWorkOrder(['code' => 'WO-ROW-LAIN', 'team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS]);

        $this->actingAs($this->admin)
            ->get(route('admin.harsus.index'))
            ->assertOk()
            ->assertSee('WO-HARSUS-CHILD')
            ->assertSee('Asal: WO-POSKO-SUMBER')
            ->assertSee('Limpahan (eskalasi)')
            ->assertDontSee('WO-ROW-LAIN')
            ->assertSee('HARSUS bukan pelaksana P0')
            ->assertDontSee('P0 HARSUS');

        $this->actingAs($this->admin)
            ->get(route('admin.harsus.index', ['source' => WorkOrder::SOURCE_ADMIN_MANUAL]))
            ->assertOk()
            ->assertDontSee('WO-HARSUS-CHILD')
            ->assertSee('Belum ada WO Pekerjaan Khusus.');
    }

    public function test_generic_work_order_detail_adapts_to_team_type(): void
    {
        $row = $this->makeWorkOrder(['team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_PANGKAS]);

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $row))
            ->assertOk()
            ->assertSee('Kembali ke ROW')
            ->assertSee(route('admin.row.index'), false)
            ->assertDontSee('Realisasi APKUK')
            ->assertSee('id="laporan"', false)
            ->assertSee('id="reschedule"', false);

        $apkuk = $this->makeWorkOrder();
        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $apkuk))
            ->assertOk()
            ->assertSee('Kembali ke WO')
            ->assertSee('Realisasi APKUK');
    }

    public function test_admin_cannot_cancel_row_or_harsus_work_orders_until_rules_exist(): void
    {
        foreach ([[WorkOrder::TEAM_ROW, WorkOrder::TYPE_RABAS], [WorkOrder::TEAM_HARSUS, WorkOrder::TYPE_PEKERJAAN_KHUSUS]] as [$team, $type]) {
            $wo = $this->makeWorkOrder(['team_type' => $team, 'work_type' => $type, 'shift_code' => null]);

            $this->actingAs($this->admin)
                ->get(route('admin.work-orders.show', $wo))
                ->assertOk()
                ->assertSee("Pembatalan WO {$team} belum tersedia")
                ->assertDontSee('action="'.route('admin.work-orders.cancel', $wo).'"', false)
                ->assertDontSee('name="cancel_reason"', false);

            $this->actingAs($this->admin)
                ->from(route('admin.work-orders.show', $wo))
                ->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Coba batalkan langsung'])
                ->assertRedirect(route('admin.work-orders.show', $wo))
                ->assertSessionHasErrors('status');

            $wo->refresh();
            $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->status);
            $this->assertNull($wo->cancelled_at);
            $this->assertNull($wo->cancel_reason);
            $this->assertSame(0, WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->count());
        }

        // Kontrol: WO APKUK tetap dapat dibatalkan melalui alur beraudit yang sama.
        $apkuk = $this->makeWorkOrder();
        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $apkuk))
            ->assertSee('action="'.route('admin.work-orders.cancel', $apkuk).'"', false);
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.cancel', $apkuk), ['cancel_reason' => 'Salah pilih POSKO'])
            ->assertRedirect(route('admin.work-orders.show', $apkuk))
            ->assertSessionHasNoErrors();
        $this->assertSame(WorkOrderStatus::Dibatalkan, $apkuk->refresh()->status);
        $this->assertSame(1, WorkOrderStatusLog::query()->where('work_order_id', $apkuk->id)->where('action', WorkOrderStatusLog::ACTION_CANCELLED)->count());
    }

    public function test_dashboard_reports_network_map_as_available(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.dashboard'))
            ->assertOk()
            ->assertJsonPath('dataReadiness.map.available', true)
            ->assertJsonPath('dataReadiness.map.route_name', 'admin.maps.index')
            ->assertJsonPath('dataReadiness.transactions.available', false)
            ->assertJsonPath('dataReadiness.network.available', false);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Peta Jaringan tersedia')
            ->assertSee('Buka Peta Jaringan')
            ->assertSee('Peta Jaringan aktif')
            ->assertDontSee('Peta Jaringan belum tersedia');
    }

    public function test_dashboard_links_team_summaries_to_new_modules(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Buka HARSUS')
            ->assertSee('Buka Inspeksi')
            ->assertSee('Buka ROW')
            ->assertSee('Buka halaman PASILA')
            ->assertDontSee('Halaman tim belum tersedia');
    }
}
