<?php

namespace Tests\Feature\Admin;

use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->get('/admin/dashboard')
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_read_dashboard_json(): void
    {
        $user = User::factory()->create([
            'username' => 'POSKO_USER',
            'role' => 'POSKO',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/admin/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_uses_active_masters_and_marks_transaction_data_unavailable(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (range(1, 6) as $number) {
            Posko::query()->create([
                'kode_posko' => sprintf('PSK-%02d', $number),
                'nama_posko' => sprintf('Posko %02d', $number),
                'is_active' => true,
            ]);
        }

        Posko::query()->create([
            'kode_posko' => 'PSK-99',
            'nama_posko' => 'Posko Nonaktif',
            'is_active' => false,
        ]);

        foreach (range(1, 2) as $number) {
            Penyulang::query()->create([
                'kode_penyulang' => sprintf('PNY-%02d', $number),
                'nama_penyulang' => sprintf('Penyulang %02d', $number),
                'is_active' => true,
            ]);
        }

        Penyulang::query()->create([
            'kode_penyulang' => 'PNY-99',
            'nama_penyulang' => 'Penyulang Nonaktif',
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response
            ->assertOk()
            ->assertSee('6 Posko aktif')
            ->assertSee('2 Penyulang')
            ->assertSee('Realisasi — / Target 6')
            ->assertSee('Data realisasi belum tersedia')
            ->assertSee('Data aktivitas belum tersedia')
            ->assertDontSee('Posko Nonaktif')
            ->assertDontSee('Semua tervalidasi')
            ->assertDontSee('href="#"', false);
    }

    public function test_admin_layout_provides_resizable_sidebar_and_mobile_drawer(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['/admin/dashboard', '/admin/p0', '/admin/work-orders', '/admin/account'] as $url) {
            $this->actingAs($admin)
                ->get($url)
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
                ->assertSee('adminSidebarCollapsed', false)
                ->assertDontSee('href="#"', false);
        }
    }

    public function test_dashboard_uses_compact_responsive_sections(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('lg:grid-cols-3', false)
            ->assertSee('xl:grid-cols-4', false)
            ->assertSee('<details', false)
            ->assertSee('table-fixed', false)
            ->assertSee('md:hidden', false);
    }

    public function test_dashboard_json_keeps_unknown_transaction_values_as_null(): void
    {
        $admin = User::factory()->admin()->create();
        Posko::query()->create([
            'kode_posko' => 'PSK-01',
            'nama_posko' => 'Posko Utama',
            'is_active' => true,
        ]);
        Penyulang::query()->create([
            'kode_penyulang' => 'PNY-01',
            'nama_penyulang' => 'Penyulang Utama',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonCount(6, 'kpis')
            ->assertJsonCount(4, 'teamSummaries')
            ->assertJsonCount(1, 'poskoStatuses')
            ->assertJsonPath('kpis.wo_active.value', null)
            ->assertJsonPath('kpis.wo_active.available', false)
            ->assertJsonPath('kpis.approvals.value', null)
            ->assertJsonPath('poskoStatuses.0.apkuk_target', 6)
            ->assertJsonPath('poskoStatuses.0.apkuk_completed', null)
            ->assertJsonPath('poskoStatuses.0.transactions_available', false)
            ->assertJsonPath('dataReadiness.masters.active_poskos', 1)
            ->assertJsonPath('dataReadiness.masters.active_penyulangs', 1)
            ->assertJsonPath('dataReadiness.transactions.available', false)
            ->assertJsonPath('recentActivities.available', false)
            ->assertJsonPath('recentActivities.items', []);
    }

    public function test_dashboard_time_context_uses_asia_jakarta(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 8, 9, 15, 0, 'Asia/Jakarta'));

        try {
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->getJson('/admin/dashboard')
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
