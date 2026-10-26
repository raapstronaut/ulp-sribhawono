<?php

namespace Tests\Feature\Admin\Foundation;

use App\Enums\WorkOrderStatus;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReschedule;
use Illuminate\Support\Facades\Schema;

class ControlModulesTest extends FoundationTestCase
{
    public function test_review_lists_only_pending_queues_with_disabled_decisions(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $pending = $this->makeWorkOrder(['code' => 'WO-VERIFIKASI', 'posko_id' => $posko->id, 'status' => WorkOrderStatus::MenungguVerifikasi]);
        $done = $this->makeWorkOrder(['code' => 'WO-SUDAH-ACC', 'posko_id' => $posko->id]);
        WorkOrderReport::create([
            'work_order_id' => $pending->id,
            'report_type' => WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP,
            'verification_status' => WorkOrderReport::VERIFICATION_MENUNGGU,
            'occurred_at' => now(),
        ]);
        WorkOrderReport::create([
            'work_order_id' => $done->id,
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'verification_status' => WorkOrderReport::VERIFICATION_TIDAK_PERLU,
        ]);
        $rescheduleWo = $this->makeWorkOrder(['code' => 'WO-RESCHEDULE', 'status' => WorkOrderStatus::RescheduleDiajukan]);
        WorkOrderReschedule::create([
            'work_order_id' => $rescheduleWo->id,
            'reason_code' => 'CUACA',
            'note' => 'Hujan lebat sejak siang',
            'from_work_date' => '2026-10-20',
            'proposed_work_date' => '2026-10-22',
            'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);
        WorkOrderReschedule::create([
            'work_order_id' => $done->id,
            'reason_code' => 'LAMA',
            'note' => 'Sudah diputuskan',
            'from_work_date' => '2026-10-19',
            'proposed_work_date' => '2026-10-20',
            'status' => WorkOrderReschedule::STATUS_DISETUJUI,
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.reviews.index'))
            ->assertOk()
            ->assertSee('WO-VERIFIKASI')
            ->assertSee('TEMUAN_SUDAH_LENGKAP')
            ->assertSee(route('admin.work-orders.show', $pending).'#laporan', false)
            ->assertSee('WO-RESCHEDULE')
            ->assertSee('Hujan lebat sejak siang')
            ->assertDontSee('Sudah diputuskan')
            ->assertSee('P0 SYSTEM menunggu ACC')
            ->assertSee('Keputusan ACC/Tolak belum diaktifkan')
            ->getContent();

        // Dua antrean × (ACC + Tolak) dan tidak ada form tulis selain logout.
        $this->assertSame(4, substr_count($html, 'disabled aria-disabled="true" title="Keputusan ACC/Tolak'));
        $this->assertSame(1, substr_count($html, 'method="POST"'));
    }

    public function test_review_filters_by_category_and_posko(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $other = Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        $mine = $this->makeWorkOrder(['code' => 'WO-PUGUNG', 'posko_id' => $posko->id]);
        $theirs = $this->makeWorkOrder(['code' => 'WO-JABUNG', 'posko_id' => $other->id]);
        foreach ([$mine, $theirs] as $wo) {
            WorkOrderReport::create([
                'work_order_id' => $wo->id,
                'report_type' => WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP,
                'verification_status' => WorkOrderReport::VERIFICATION_MENUNGGU,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['category' => 'reports', 'posko_id' => $posko->id]))
            ->assertOk()
            ->assertSee('WO-PUGUNG')
            ->assertDontSee('WO-JABUNG')
            ->assertDontSee('Pengajuan Reschedule</h2>', false);

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['category' => 'reschedules']))
            ->assertOk()
            ->assertSee('Tidak ada pengajuan reschedule.')
            ->assertDontSee('Laporan Menunggu Verifikasi</h2>', false);
    }

    public function test_special_case_groups_raw_frequency_within_period_without_flagging(): void
    {
        $this->makeGangguan('G-1', ['penyulang' => 'REOG', 'tanggal_kejadian' => '2026-10-15', 'lama_padam_menit' => 30]);
        $this->makeGangguan('G-2', ['penyulang' => 'REOG', 'tanggal_kejadian' => '2026-09-01', 'lama_padam_menit' => 45]);
        $this->makeGangguan('G-3', ['penyulang' => 'MERAPI', 'tanggal_kejadian' => '2026-10-01']);
        $this->makeGangguan('G-OLD', ['penyulang' => 'LAMA', 'tanggal_kejadian' => '2025-01-01']);
        $this->makeGangguan('G-NULL', ['penyulang' => null, 'tanggal_kejadian' => '2026-10-10']);
        $this->makeGangguan('G-NODATE', ['penyulang' => 'REOG', 'tanggal_kejadian' => null]);

        $this->actingAs($this->admin)
            ->get(route('admin.special-case.index'))
            ->assertOk()
            ->assertSee('Metode Special Case belum final')
            ->assertSeeInOrder(['REOG', '2', '75', '15/10/2026', 'MERAPI', '1'])
            ->assertSee('(kosong)')
            ->assertDontSee('>LAMA<', false)
            ->assertSee('Tanpa tanggal kejadian')
            ->assertSee(route('admin.special-case.show', ['dimension' => 'penyulang', 'key' => 'REOG', 'period' => 90]));

        $this->actingAs($this->admin)
            ->get(route('admin.special-case.index', ['period' => 365, 'dimension' => 'peralatan']))
            ->assertOk()
            ->assertSee('Frekuensi gangguan per Peralatan')
            ->assertSee('REC TEST');

        // Dimensi di luar daftar putih jatuh kembali ke penyulang (tidak masuk ke SQL).
        $this->actingAs($this->admin)
            ->get(route('admin.special-case.index', ['dimension' => 'penyulang; drop table gangguans', 'period' => 7]))
            ->assertOk()
            ->assertSee('Frekuensi gangguan per Penyulang');
        $this->assertTrue(Schema::hasTable('gangguans'));
    }

    public function test_out_of_range_pages_redirect_to_last_page_keeping_filters(): void
    {
        $this->makeGangguan('G-1', ['penyulang' => 'REOG']);
        $wo = $this->makeWorkOrder();
        WorkOrderReport::create([
            'work_order_id' => $wo->id,
            'report_type' => WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP,
            'verification_status' => WorkOrderReport::VERIFICATION_MENUNGGU,
        ]);

        $queryOf = function ($response): array {
            parse_str((string) parse_url((string) $response->assertRedirect()->headers->get('Location'), PHP_URL_QUERY), $query);

            return $query;
        };

        $special = $queryOf($this->actingAs($this->admin)->get(route('admin.special-case.index', ['period' => 365, 'page' => 2])));
        $this->assertEquals(['period' => '365', 'page' => '1'], $special);

        // Dua paginator di satu halaman: hanya nomor halaman yang keliru yang dikoreksi.
        $review = $queryOf($this->actingAs($this->admin)->get(route('admin.reviews.index', ['reports_page' => 4, 'team_type' => 'POSKO'])));
        $this->assertEquals(['reports_page' => '1', 'team_type' => 'POSKO'], $review);

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['reports_page' => 1, 'reschedules_page' => 5]))
            ->assertOk()
            ->assertSee('Tidak ada pengajuan reschedule.');
    }

    public function test_special_case_detail_lists_events_and_supports_empty_key(): void
    {
        $this->makeGangguan('G-REOG-1', ['penyulang' => 'REOG', 'tanggal_kejadian' => '2026-10-15', 'penyebab' => 'LAYANG-LAYANG']);
        $this->makeGangguan('G-MERAPI', ['penyulang' => 'MERAPI', 'tanggal_kejadian' => '2026-10-14']);
        $this->makeGangguan('G-KOSONG', ['penyulang' => null, 'tanggal_kejadian' => '2026-10-13']);

        $this->actingAs($this->admin)
            ->get(route('admin.special-case.show', ['dimension' => 'penyulang', 'key' => 'REOG']))
            ->assertOk()
            ->assertSee('G-REOG-1')
            ->assertSee('LAYANG-LAYANG')
            ->assertDontSee('G-MERAPI');

        $this->actingAs($this->admin)
            ->get(route('admin.special-case.show', ['dimension' => 'penyulang', 'key' => '']))
            ->assertOk()
            ->assertSee('G-KOSONG')
            ->assertDontSee('G-REOG-1');
    }

    public function test_module_shows_error_state_when_source_table_is_unavailable(): void
    {
        Schema::drop('gangguans');

        $this->actingAs($this->admin)
            ->get(route('admin.special-case.index'))
            ->assertOk()
            ->assertSee('Data tidak dapat dimuat')
            ->assertSee('role="alert"', false)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($this->admin)
            ->get(route('admin.analytics.index'))
            ->assertOk()
            ->assertSee('Data tidak dapat dimuat')
            ->assertSee('Work Order per tim dan status');
    }

    public function test_review_uses_work_order_team_scope_for_harsus_and_row(): void
    {
        $row = $this->makeWorkOrder(['code' => 'WO-ROW-REPORT', 'team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS]);
        WorkOrderReport::create([
            'work_order_id' => $row->id,
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'verification_status' => WorkOrderReport::VERIFICATION_MENUNGGU,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['team_type' => WorkOrder::TEAM_HARSUS]))
            ->assertOk()
            ->assertDontSee('WO-ROW-REPORT');

        $this->actingAs($this->admin)
            ->get(route('admin.reviews.index', ['team_type' => WorkOrder::TEAM_ROW]))
            ->assertOk()
            ->assertSee('WO-ROW-REPORT');
    }
}
