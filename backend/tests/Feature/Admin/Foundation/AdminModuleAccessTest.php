<?php

namespace Tests\Feature\Admin\Foundation;

use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusLog;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class AdminModuleAccessTest extends FoundationTestCase
{
    private const FOUNDATION_ROUTE_PREFIXES = [
        'admin.pasila.',
        'admin.inspeksi.',
        'admin.row.',
        'admin.harsus.',
        'admin.reviews.',
        'admin.special-case.',
        'admin.master-data.',
        'admin.analytics.',
        'admin.users.',
        'admin.audit-logs.',
    ];

    public function test_guests_are_redirected_to_login_for_every_module_page(): void
    {
        foreach ($this->moduleUrls() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_non_admin_and_inactive_admin_are_forbidden_on_every_module_page(): void
    {
        $urls = $this->moduleUrls();
        $fieldAccount = User::factory()->create(['username' => 'POSKO_ACCOUNT', 'role' => 'POSKO', 'is_active' => true]);
        $inactiveAdmin = User::factory()->inactiveAdmin()->create(['username' => 'OLD_ADMIN']);

        foreach ([$fieldAccount, $inactiveAdmin] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_active_admin_can_open_every_module_page_with_title_and_state_badge(): void
    {
        $expectations = [
            '/admin/pasila' => 'PASILA Monitor',
            '/admin/inspeksi' => 'Inspeksi',
            '/admin/row' => 'ROW',
            '/admin/harsus' => 'HARSUS',
            '/admin/review' => 'Review & Validasi',
            '/admin/special-case' => 'Special Case',
            '/admin/master-data' => 'Master Data',
            '/admin/analitik' => 'Analitik',
            '/admin/users' => 'User & Hak Akses',
            '/admin/audit-log' => 'Audit Log & Histori',
        ];

        foreach ($expectations as $url => $title) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertSee('<h1 class="text-2xl font-bold leading-tight text-slate-900">'.e($title).'</h1>', false)
                ->assertSee('rounded-md border px-2 py-0.5 text-[10px] font-bold', false)
                ->assertDontSee('href="#"', false);
        }
    }

    public function test_desktop_and_mobile_navigation_link_every_module_and_no_pending_menu_remains(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/dashboard')->assertOk()->getContent();

        foreach (AdminNavigation::routeNames() as $routeName) {
            $href = 'href="'.route($routeName).'"';
            $this->assertGreaterThanOrEqual(2, substr_count($html, $href), "Menu {$routeName} harus ada di sidebar desktop dan navigasi seluler.");
        }

        $this->assertStringNotContainsString('modul belum tersedia', $html);
        $this->assertStringNotContainsString('Modul lainnya belum tersedia', $html);
        $this->assertStringContainsString('id="mobile-sidebar"', $html);
        $this->assertCount(15, AdminNavigation::routeNames());
    }

    public function test_current_module_is_marked_active_in_navigation(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/special-case')->assertOk()->getContent();
        $activeLink = fn (string $routeName) => preg_match_all('/<a href="'.preg_quote(route($routeName), '/').'"[^>]*aria-current="page"/', $html);

        // Desktop + seluler.
        $this->assertSame(2, $activeLink('admin.special-case.index'));
        $this->assertSame(0, $activeLink('admin.pasila.index'));
        $this->assertSame(0, $activeLink('admin.dashboard'));
        $this->assertStringContainsString('<title>Special Case - ULP Sribhawono</title>', $html);
    }

    public function test_foundation_routes_are_read_only_and_inside_admin_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => collect(self::FOUNDATION_ROUTE_PREFIXES)->contains(fn ($prefix) => str_starts_with((string) $route->getName(), $prefix)));

        $this->assertCount(17, $routes);

        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$route->getName()} harus baca saja.");
            $this->assertContains('auth', $route->gatherMiddleware(), "{$route->getName()} wajib auth.");
            $this->assertContains('admin', $route->gatherMiddleware(), "{$route->getName()} wajib admin.");
        }

        $this->actingAs($this->admin)->post('/admin/pasila')->assertMethodNotAllowed();
        $this->actingAs($this->admin)->post('/admin/review')->assertMethodNotAllowed();
    }

    public function test_opening_every_module_page_does_not_write_any_data(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $penyulang = Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);
        $wo = $this->makeWorkOrder(['posko_id' => $posko->id, 'penyulang_id' => $penyulang->id]);
        WorkOrderStatusLog::create(['work_order_id' => $wo->id, 'action' => WorkOrderStatusLog::ACTION_CREATED, 'to_status' => 'DITUGASKAN']);
        $this->makeGangguan('G-1');

        $tables = ['work_orders', 'work_order_status_logs', 'work_order_reports', 'work_order_reschedules', 'gangguans', 'users', 'poskos', 'penyulangs'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $updatedAt = DB::table('work_orders')->value('updated_at');

        foreach ($this->moduleUrls() as $url) {
            $this->actingAs($this->admin)->get($url);
        }

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]));
        $this->assertSame($updatedAt, DB::table('work_orders')->value('updated_at'));
    }

    /**
     * @return array<int, string>
     */
    private function moduleUrls(): array
    {
        $posko = Posko::query()->first() ?? Posko::create(['kode_posko' => 'ACCESS', 'nama_posko' => 'ACCESS']);
        $penyulang = Penyulang::query()->first() ?? Penyulang::create(['kode_penyulang' => 'ACCESS', 'nama_penyulang' => 'ACCESS']);
        $log = WorkOrderStatusLog::query()->first() ?? WorkOrderStatusLog::create([
            'work_order_id' => (WorkOrder::query()->first() ?? $this->makeWorkOrder())->id,
            'action' => WorkOrderStatusLog::ACTION_CREATED,
        ]);

        return [
            '/admin/pasila',
            '/admin/pasila/'.$posko->id,
            '/admin/inspeksi',
            '/admin/inspeksi/INS-001',
            '/admin/row',
            '/admin/harsus',
            '/admin/review',
            '/admin/special-case',
            '/admin/special-case/detail?dimension=penyulang&key=REOG',
            '/admin/master-data',
            '/admin/master-data/posko/'.$posko->id,
            '/admin/master-data/penyulang/'.$penyulang->id,
            '/admin/analitik',
            '/admin/users',
            '/admin/users/'.$this->admin->id,
            '/admin/audit-log',
            '/admin/audit-log/'.$log->id,
        ];
    }
}
