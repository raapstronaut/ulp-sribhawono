<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;

/** V2.2 UI-only checks: GET filters, search/reset and operational navigation. */
class WebsiteOperationalWorkspaceV22Test extends OperationsTestCase
{
    public function test_four_operational_index_pages_have_consistent_search_reset_and_panel(): void
    {
        $pages = [
            ['admin.work-orders.index', 'PENCARIAN & FILTER POSKO'],
            ['admin.row.index', 'PENCARIAN & FILTER ROW'],
            ['admin.harsus.index', 'PENCARIAN & FILTER HARSUS'],
            ['admin.inspeksi.index', 'PENCARIAN & FILTER INSPEKSI'],
        ];

        foreach ($pages as [$routeName, $heading]) {
            $response = $this->actingAs($this->admin)->get(route($routeName))->assertOk();
            $response
                ->assertSee('pln-operations-workspace', false)
                ->assertSee('pln-operations-list', false)
                ->assertSee('pln-operations-filter', false)
                ->assertSee($heading)
                ->assertSee('>Search</button>', false)
                ->assertSee('>Reset</a>', false);
        }

        $this->assertSame(0, DB::table('work_orders')->count());
        $this->assertSame(0, DB::table('inspection_findings')->count());
    }

    public function test_inspection_index_links_to_read_only_acc_review_queue(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.inspeksi.index'))
            ->assertOk()
            ->assertSee('Antrean temuan ACC')
            ->getContent();

        $this->assertStringContainsString('href="'.route('admin.inspeksi.queue').'"', $html);
        $this->actingAs($this->admin)->get(route('admin.inspeksi.queue'))->assertOk();
        $this->assertSame(0, DB::table('work_orders')->count());
    }
}
