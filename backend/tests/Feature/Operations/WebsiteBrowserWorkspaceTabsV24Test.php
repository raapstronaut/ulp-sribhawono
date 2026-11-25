<?php

namespace Tests\Feature\Operations;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/** V2.4 browser-like tabs: server renders only dashboard + current; history lives in sessionStorage. */
class WebsiteBrowserWorkspaceTabsV24Test extends OperationsTestCase
{
    public function test_initial_dashboard_has_only_one_tab_and_bootstrap_metadata(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $strip = $this->strip($html);

        $this->assertSame(1, substr_count($strip, '<a '));
        $this->assertSame(1, substr_count($strip, '>Dashboard</a>'));
        $this->assertSame(1, substr_count($strip, 'aria-current="location"'));
        $this->assertStringContainsString('data-dashboard-url="'.route('admin.dashboard').'"', $html);
        $this->assertStringContainsString('data-current-label="Dashboard"', $html);
        $this->assertStringContainsString('pln-workspace-tabs-v24:', $html);
        $this->assertStringNotContainsString('>INSPEKSI</a>', $strip);
        $this->assertStringNotContainsString('>ROW</a>', $strip);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_each_operational_page_only_renders_dashboard_plus_its_current_tab(): void
    {
        foreach ([
            ['admin.row.index', 'ROW'],
            ['admin.harsus.index', 'HARSUS'],
            ['admin.inspeksi.index', 'INSPEKSI'],
            ['admin.work-orders.index', 'POSKO'],
        ] as [$routeName, $label]) {
            $html = $this->actingAs($this->admin)->get(route($routeName))->assertOk()->getContent();
            $strip = $this->strip($html);
            $this->assertSame(2, substr_count($strip, '<a '), $routeName);
            $this->assertSame(1, substr_count($strip, 'aria-current="location"'), $routeName);
            $this->assertStringContainsString('data-current-label="'.$label.'"', $html);
            $this->assertStringContainsString('>'.$label.'</a>', $strip);
        }
        $this->assertSame(0, DB::table('work_orders')->count());
        $this->assertSame(0, DB::table('inspection_findings')->count());
    }

    public function test_special_case_keeps_exactly_two_active_sidebar_links(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.special-case.index'))->assertOk()->getContent();
        $activeSidebar = preg_match_all(
            '/<a href="'.preg_quote(route('admin.special-case.index'), '/').'"[^>]*aria-current="page"/',
            $html
        );

        // Desktop + mobile sidebar; tab browser jangan dianggap sebagai sidebar.
        $this->assertSame(2, $activeSidebar);
        $strip = $this->strip($html);
        $this->assertSame(0, substr_count($strip, 'aria-current="page"'));
        $this->assertSame(1, substr_count($strip, 'aria-current="location"'));
        $this->assertStringContainsString('data-current-label="Special Case"', $html);
        $this->assertStringContainsString('aria-current="location">Special Case</a>', $strip);
    }

    private function strip(string $html): string
    {
        $count = preg_match('/<div class="pln-workspace-strip"[^>]*>(.*?)<\/div>/s', $html, $match);
        $this->assertSame(1, $count);
        return $match[1];
    }
}
