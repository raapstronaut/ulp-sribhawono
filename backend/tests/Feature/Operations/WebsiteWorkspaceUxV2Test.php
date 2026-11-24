<?php

namespace Tests\Feature\Operations;

use App\Support\AdminNavigation;
use App\Support\AdminWorkspaceMenu;
use Illuminate\Support\Facades\DB;

/**
 * Kontrak visual-navigation dan filter sumber V2: tanpa write / tanpa generator.
 */
class WebsiteWorkspaceUxV2Test extends OperationsTestCase
{
    public function test_workspace_tree_keeps_every_original_menu_route_without_duplicates(): void
    {
        $expected = collect(AdminNavigation::sections())
            ->flatMap(fn (array $section): array => $section['items'])
            ->pluck('route')->sort()->values()->all();
        $actual = collect(AdminWorkspaceMenu::groups())->flatMap(function (array $group) {
            return collect($group['nodes'])->flatMap(fn (array $node) =>
                isset($node['item']) ? [$node['item']['route']] : collect($node['children'])->pluck('item')->pluck('route')->all()
            );
        })->sort()->values()->all();
        $this->assertSame($expected, $actual);

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('class="pln-tree-group"', $html);
        $this->assertStringContainsString('class="pln-workspace-strip"', $html);
        $this->assertStringContainsString('MENU PENDUKUNG', $html);
        foreach ($expected as $route) {
            $this->assertGreaterThanOrEqual(2, substr_count($html, 'href="'.route($route).'"'));
        }
    }

    public function test_asset_filter_search_reset_and_tabbed_detail_are_read_only(): void
    {
        $first = DB::table('asset_source_rows')->insertGetId([
            'id_aset_raw' => 'UX-ASSET-001', 'nama_aset_raw' => 'TIANG DEMO',
            'jenis_aset_raw' => 'TIANG', 'penyulang_raw' => 'REOG', 'status_raw' => 'VALID',
            'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('asset_source_rows')->insert([
            'id_aset_raw' => 'UX-ASSET-002', 'nama_aset_raw' => 'TRAFO LAIN',
            'jenis_aset_raw' => 'TRAFO', 'penyulang_raw' => 'BAUNG 2', 'status_raw' => 'DRAFT',
            'source_row_number' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.aset.index', ['q' => 'UX-ASSET', 'jenis' => 'TIANG', 'penyulang' => 'REOG', 'status' => 'VALID']))
            ->assertOk()->assertSee('UX-ASSET-001')->assertDontSee('UX-ASSET-002')
            ->assertSee('Search')->assertSee('Reset')->assertSee('FILTER PENCARIAN ASET');

        $this->actingAs($this->admin)->get(route('admin.aset.show', $first))
            ->assertOk()->assertSee('TIANG DEMO')->assertSee('role="tablist"', false)
            ->assertSee('data-asset-tab="histori"', false)
            ->assertSee('Histori Pemasangan')->assertSee('belum diaktifkan')
            ->assertSee('Belum tersedia');

        $this->assertSame(2, DB::table('asset_source_rows')->count());
        $this->assertSame(0, DB::table('work_orders')->count());
    }
}
