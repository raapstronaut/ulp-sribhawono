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

    public function test_pasila_monitor_shows_real_shift_context_and_honest_state_without_sessions(): void
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
            ->assertSee('Shift A · Pagi')
            ->assertSee('PUGUNG')
            ->assertSee('JABUNG')
            // Tanpa sesi tercatat: status sesi, WO APKUK, dan P0 tetap kolom terpisah.
            ->assertSeeInOrder(['PUGUNG', 'Belum dibuka', 'Pembuka sesi belum dijalankan untuk shift berjalan.', '1/6', '2 WO · 1 aktif', '1 reschedule menunggu', 'data-p0-obligation=""'], false)
            ->assertSee('Buka sesi shift berjalan')
            ->assertSee('PASILA_OPENER_ENABLED=false')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee(route('admin.pasila.show', ['posko' => $this->pugung, 'work_date' => '2026-10-20', 'shift_code' => 'A']));
    }

    public function test_pasila_filters_by_posko_and_shift_and_ignores_invalid_dates(): void
    {
        $this->makeWorkOrder(['posko_id' => $this->pugung->id, 'shift_code' => 'B']);

        $this->actingAs($this->admin)
            ->get(route('admin.pasila.index', ['posko_id' => $this->jabung->id, 'shift_code' => 'B', 'work_date' => '2026-02-31']))
            ->assertOk()
            ->assertSee('Shift B · Sore')->assertSee('20 Oct 2026')
            ->assertSee('JABUNG')
            ->assertDontSee('2 WO · 1 aktif');

        $this->actingAs($this->admin)
            ->get(route('admin.pasila.index', ['shift_code' => 'B']))
            ->assertOk()
            ->assertSee('1 WO · 1 aktif');
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
            ->assertSee('Sesi PASILA shift ini')
            ->assertSee('Belum ada sesi PASILA.')
            ->assertSee('Tersedia setelah sesi PASILA shift ini dibuka.')
            ->assertSee('Belum ada Section terverifikasi.');
    }

    public function test_pasila_detail_returns_404_for_unknown_posko(): void
    {
        $this->actingAs($this->admin)->get('/admin/pasila/not-a-uuid')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/pasila/9d3b1a4e-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_inspeksi_lists_visits_with_real_team_roster(): void
    {
        $team = FieldTeam::create(['code' => 'INSPEKSI-1', 'name' => 'Tim Inspeksi ULP', 'role' => 'INSPEKSI']);
        $team->personnel()->create(['name' => 'Petugas Satu']);
        FieldTeam::create(['code' => 'ROW-1', 'name' => 'Tim ROW ULP', 'role' => 'ROW']);

        $this->actingAs($this->admin)
            ->get(route('admin.inspeksi.index'))
            ->assertOk()
            ->assertSee('Sebagian data tersedia')
            ->assertSee('Tim Inspeksi ULP')
            ->assertSee('1 petugas aktif')
            ->assertSee('Belum ada kunjungan inspeksi.')
            ->assertSee(route('admin.inspeksi.create'), false);

        // ID bukan UUID ditolak 404 setelah otorisasi Admin, tanpa error database.
        $this->actingAs($this->admin)->get('/admin/inspeksi/INS-404')->assertNotFound();
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
            ->assertSee(route('admin.row.index'), false)
            ->assertDontSee('Realisasi APKUK')
            ->assertSee('id="laporan"', false)
            ->assertSee('id="reschedule"', false);

        $apkuk = $this->makeWorkOrder();
        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $apkuk))
            ->assertOk()
            ->assertSee(route('admin.work-orders.index'), false)
            ->assertSee('Realisasi APKUK');
    }

    public function test_admin_can_cancel_manual_row_and_harsus_but_not_escalation_or_system_work_orders(): void
    {
        // WO manual Admin tanpa keterlibatan limpahan: dapat dibatalkan dengan alasan dan audit.
        foreach ([[WorkOrder::TEAM_ROW, WorkOrder::TYPE_RABAS], [WorkOrder::TEAM_HARSUS, WorkOrder::TYPE_PEKERJAAN_KHUSUS]] as [$team, $type]) {
            $wo = $this->makeWorkOrder(['team_type' => $team, 'work_type' => $type, 'shift_code' => null]);
            $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))
                ->assertSee('action="'.route('admin.work-orders.cancel', $wo).'"', false);
            $this->actingAs($this->admin)
                ->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Pekerjaan digabung'])
                ->assertRedirect(route('admin.work-orders.show', $wo))
                ->assertSessionHasNoErrors();
            $this->assertSame(WorkOrderStatus::Dibatalkan, $wo->refresh()->status);
        }

        // WO otomatis sistem dan child limpahan: aturan batal belum disahkan.
        $source = $this->makeWorkOrder(['code' => 'WO-SUMBER', 'status' => WorkOrderStatus::SelesaiDiteruskan]);
        $blocked = [
            $this->makeWorkOrder(['team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS, 'source' => WorkOrder::SOURCE_SYSTEM, 'shift_code' => null]),
            $this->makeWorkOrder(['team_type' => WorkOrder::TEAM_HARSUS, 'work_type' => WorkOrder::TYPE_PEKERJAAN_KHUSUS, 'source' => WorkOrder::SOURCE_ESCALATION, 'parent_work_order_id' => $source->id, 'shift_code' => null]),
        ];
        foreach ($blocked as $wo) {
            $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))
                ->assertOk()
                ->assertSee('belum dapat dibatalkan')
                ->assertDontSee('name="cancel_reason"', false);
            $this->actingAs($this->admin)
                ->from(route('admin.work-orders.show', $wo))
                ->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Coba batalkan langsung'])
                ->assertRedirect(route('admin.work-orders.show', $wo))
                ->assertSessionHasErrors('status');
            $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->refresh()->status);
            $this->assertSame(0, WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->count());
        }
    }

    public function test_dashboard_links_network_map_and_team_modules(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Lihat Peta Jaringan')
            ->assertSee(route('admin.maps.index'), false)
            ->assertSee('Lihat Halaman HARSUS')
            ->assertSee('Lihat Halaman INSPEKSI')
            ->assertSee('Lihat Halaman ROW')
            ->assertSee(route('admin.pasila.index'), false)
            ->assertDontSee('Halaman tim belum tersedia');
    }
}
