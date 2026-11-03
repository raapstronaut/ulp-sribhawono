<?php

namespace Tests\Feature\Admin;

use App\Enums\WorkOrderStatus;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_read_dashboard_json(): void
    {
        $user = User::factory()->create([
            'username' => 'POSKO_USER',
            'role' => 'POSKO',
            'is_active' => true,
        ]);

        $this->actingAs($user)->getJson('/admin/dashboard')->assertForbidden();
    }

    public function test_empty_database_shows_zero_not_fake_numbers(): void
    {
        $admin = User::factory()->admin()->create();
        Posko::query()->create(['kode_posko' => 'PSK-01', 'nama_posko' => 'Posko Utama', 'is_active' => true]);
        Penyulang::query()->create(['kode_penyulang' => 'PNY-01', 'nama_penyulang' => 'Penyulang Utama', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis.total', 0)
            ->assertJsonPath('kpis.active', 0)
            ->assertJsonPath('kpis.terlambat', 0)
            ->assertJsonCount(3, 'teamSummaries')
            ->assertJsonCount(1, 'poskoStatuses')
            ->assertJsonPath('poskoStatuses.0.apkuk_target', 6)
            ->assertJsonPath('poskoStatuses.0.apkuk_total', 0)
            ->assertJsonPath('context.active_poskos', 1)
            ->assertJsonPath('context.active_penyulangs', 1)
            ->assertJsonPath('recentActivities', [])
            ->assertJsonPath('insights.period', '30d')
            ->assertJsonPath('insights.charts.POSKO.total_first', 0)
            ->assertJsonPath('insights.charts.INSPEKSI.total_second', 0);

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('Monitoring Harian')
            ->assertSee('Ringkasan Per Bidang')
            ->assertSee('Analitik &amp; Tren', false)
            ->assertSee('Apresiasi Bulanan')
            ->assertSee('Belum ditetapkan');
    }

    public function test_dashboard_counts_follow_database_changes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 28, 9, 0, 0, 'Asia/Jakarta'));

        try {
            $admin = User::factory()->admin()->create();
            $posko = Posko::query()->create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG', 'is_active' => true]);
            $make = fn (array $attributes) => WorkOrder::query()->forceCreate(array_merge([
                'code' => 'WO-'.uniqid(),
                'team_type' => WorkOrder::TEAM_POSKO,
                'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
                'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
                'status' => WorkOrderStatus::Ditugaskan,
                'posko_id' => $posko->id,
                'work_date' => '2026-10-28',
                'shift_code' => 'A',
            ], $attributes));

            $make(['status' => WorkOrderStatus::Ditugaskan]);
            $make(['status' => WorkOrderStatus::Dikerjakan, 'due_at' => now()->subHour()]);
            $done = $make(['status' => WorkOrderStatus::Selesai, 'completed_at' => now()->subMinutes(30)]);
            $waiting = $make(['status' => WorkOrderStatus::MenungguVerifikasi, 'team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS, 'shift_code' => null]);
            WorkOrderReport::create(['work_order_id' => $waiting->id, 'report_type' => WorkOrderReport::TYPE_PEKERJAAN_SELESAI, 'verification_status' => WorkOrderReport::VERIFICATION_MENUNGGU]);

            $response = $this->actingAs($admin)->getJson('/admin/dashboard')->assertOk();
            $response
                ->assertJsonPath('kpis.total', 4)
                ->assertJsonPath('kpis.active', 3)
                ->assertJsonPath('kpis.belum', 1)
                ->assertJsonPath('kpis.dikerjakan', 1)
                ->assertJsonPath('kpis.menunggu', 1)
                ->assertJsonPath('kpis.selesai_periode', 1)
                ->assertJsonPath('kpis.terlambat', 1)
                ->assertJsonPath('pending.reports', 1)
                ->assertJsonPath('statusGroups.selesai', 1)
                ->assertJsonPath('insights.monitoring.active.POSKO', 2)
                ->assertJsonPath('insights.monitoring.active.ROW', 1)
                ->assertJsonPath('insights.monitoring.active.INSPEKSI', null)
                ->assertJsonPath('insights.monitoring.verification.ROW', 1)
                ->assertJsonPath('insights.charts.POSKO.total_first', 3)
                ->assertJsonPath('insights.charts.POSKO.total_second', 1);
            $this->assertSame(1, collect($response->json('teamSummaries'))->firstWhere('team', 'ROW')['waiting']);

            DB::table('work_orders')->where('id', $done->id)->update(['status' => WorkOrderStatus::Dibatalkan->value, 'completed_at' => null]);
            $this->actingAs($admin)->getJson('/admin/dashboard')
                ->assertJsonPath('kpis.selesai_periode', 0)
                ->assertJsonPath('statusGroups.batal', 1)
                ->assertJsonPath('insights.charts.POSKO.total_second', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_period_filter_and_html_sections(): void
    {
        $admin = User::factory()->admin()->create();
        Posko::query()->create(['kode_posko' => 'PSK-01', 'nama_posko' => 'Posko Utama', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/admin/dashboard?period=30d&trend_period=7d')
            ->assertOk()->assertJsonPath('context.period', '30d')->assertJsonPath('insights.period', '7d');
        $this->actingAs($admin)->getJson('/admin/dashboard?period=bogus&trend_period=bogus')
            ->assertOk()->assertJsonPath('context.period', 'today')->assertJsonPath('insights.period', '30d');

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('Monitoring Harian')
            ->assertSee('Ringkasan Per Bidang')
            ->assertSee('Analitik &amp; Tren', false)
            ->assertSee('Apresiasi Bulanan')
            ->assertSee('data-dashboard-open=', false)
            ->assertSee('<dialog', false);
    }

    public function test_admin_layout_provides_resizable_sidebar_and_mobile_drawer(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('id="admin-sidebar"', false)
            ->assertSee('id="sidebar-collapse-button"', false)
            ->assertSee('id="sidebar-expand-button"', false)
            ->assertSee('id="sidebar-resizer"', false)
            ->assertSee('aria-valuemin="190"', false)
            ->assertSee('aria-valuemax="300"', false)
            ->assertSee('id="mobile-sidebar"', false)
            ->assertSee('id="mobile-sidebar-backdrop"', false)
            ->assertSee('adminSidebarWidth', false)
            ->assertSee('adminSidebarCollapsed', false);
    }

    public function test_dashboard_time_context_uses_asia_jakarta(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 8, 9, 15, 0, 'Asia/Jakarta'));

        try {
            $admin = User::factory()->admin()->create();
            $this->actingAs($admin)->getJson('/admin/dashboard')
                ->assertOk()
                ->assertJsonPath('context.updated_at', '2026-10-08T09:15:00+07:00')
                ->assertJsonPath('context.updated_at_label', '08-10-2026 09:15 WIB')
                ->assertJsonPath('shiftData.code', 'A')
                ->assertJsonPath('shiftData.time', '08:00–16:00 WIB');
        } finally {
            Carbon::setTestNow();
        }
    }
}
