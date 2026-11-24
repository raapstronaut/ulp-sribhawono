<?php

namespace Tests\Unit;

use App\Support\AdminNavigation;
use PHPUnit\Framework\TestCase;

class AdminNavigationPoskoTest extends TestCase
{
    public function test_posko_is_single_sidebar_item_and_other_work_fields_are_preserved(): void
    {
        $sections = AdminNavigation::sections();
        $work = collect($sections)->firstWhere('label', 'Pekerjaan');
        $this->assertNotNull($work);
        $this->assertSame(['POSKO', 'ROW', 'HARSUS', 'INSPEKSI', 'Antrean Temuan ACC'], array_column($work['items'], 'label'));

        $posko = $work['items'][0];
        $this->assertSame('admin.work-orders.index', $posko['route']);
        $this->assertSame([
            'admin.work-orders.*', 'admin.pasila.*', 'admin.p0.*', 'admin.shift-quota.*',
        ], $posko['active']);
    }

    public function test_all_four_posko_destination_routes_remain_available(): void
    {
        $routes = AdminNavigation::routeNames();
        foreach (['admin.work-orders.index', 'admin.pasila.index', 'admin.p0.index', 'admin.shift-quota.index'] as $route) {
            $this->assertContains($route, $routes);
        }

        $sections = AdminNavigation::sections();
        $data = collect($sections)->firstWhere('label', 'Data');
        $this->assertSame('APKUK — Data Sumber', $data['items'][0]['label']);
        $this->assertContains('admin.apkuk.index', $routes);
    }
}
