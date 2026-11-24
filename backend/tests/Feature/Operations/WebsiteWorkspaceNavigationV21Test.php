<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;

/** Kontrak perapian tab workspace V2.1; hanya GET dan fixture SQLite in-memory. */
class WebsiteWorkspaceNavigationV21Test extends OperationsTestCase
{
    public function test_dashboard_shortcut_replaces_beranda_folder_in_both_sidebars(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // Desktop dan mobile sama-sama memiliki shortcut Dashboard secara langsung.
        $this->assertSame(2, substr_count($html, 'data-pln-home-shortcut'));
        $this->assertStringNotContainsString('data-pln-tree="utama"', $html);
        $this->assertSame(2, substr_count($html, 'data-pln-tree="pekerjaan"'));
        $this->assertStringContainsString('href="'.route('admin.dashboard').'"', $html);
    }

    public function test_dashboard_has_only_one_active_quick_tab_without_welcome_tab(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $strip = $this->quickTabStrip($html);

        $this->assertStringNotContainsString('Selamat Datang', $strip);
        $this->assertSame(1, substr_count($strip, '>Dashboard</a>'));
        $this->assertSame(1, substr_count($strip, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('admin.dashboard'), '/').'"\s+class="pln-workspace-current"\s+aria-current="page"\s*>Dashboard<\/a>/',
            $strip
        );
        $this->assertStringNotContainsString('<span class="pln-workspace-current">Dashboard</span>', $strip);
    }

    public function test_asset_detail_activates_one_data_asset_quick_tab(): void
    {
        $id = DB::table('asset_source_rows')->insertGetId([
            'id_aset_raw' => 'UX-V21-0001',
            'nama_aset_raw' => 'TIANG UJI V21',
            'jenis_aset_raw' => 'TIANG',
            'penyulang_raw' => 'REOG',
            'status_raw' => 'VALID',
            'source_row_number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.aset.show', $id))->assertOk()->getContent();
        $strip = $this->quickTabStrip($html);

        $this->assertSame(1, substr_count($strip, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('admin.aset.index'), '/').'"\s+class="pln-workspace-current"\s+aria-current="page"\s*>Data Aset<\/a>/',
            $strip
        );
        $this->assertStringNotContainsString('<span class="pln-workspace-current">', $strip);
        $this->assertSame(1, DB::table('asset_source_rows')->count());
        $this->assertSame(0, DB::table('work_orders')->count());
    }

    public function test_other_module_keeps_its_page_context_without_marking_dashboard_active(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.harsus.index'))->assertOk()->getContent();
        $strip = $this->quickTabStrip($html);

        $this->assertStringContainsString('>Dashboard</a>', $strip);
        $this->assertStringNotContainsString('Selamat Datang', $strip);
        $this->assertStringNotContainsString('aria-current="page"', $strip);
        $this->assertSame(1, substr_count($strip, '<span class="pln-workspace-current">'));
    }

    private function quickTabStrip(string $html): string
    {
        $count = preg_match('/<div class="pln-workspace-strip"[^>]*>(.*?)<\/div>/s', $html, $matches);
        $this->assertSame(1, $count, 'Tab workspace harus tersedia tepat satu.');

        return $matches[1];
    }
}
