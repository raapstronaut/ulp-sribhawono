<?php

namespace Tests\Feature\Operations;

use App\Models\ApkukReconciliationItem;
use Illuminate\Support\Facades\DB;

/**
 * B1.2 — Detail APKUK menampilkan 12 kolom APKUK_RAW (A–L) apa adanya, status sumber,
 * rekonsiliasi, WO terkait, dan laporan lain di tiang yang sama. Hanya untuk ADMIN.
 */
class ApkukDetailTest extends OperationsTestCase
{
    public function test_detail_shows_all_twelve_source_columns_with_original_values(): void
    {
        $id = $this->apkuk([
            'source_id' => 'A777', 'tanggal' => '2026-09-15', 'petugas' => 'Petugas Uji SPS', 'penyulang' => 'REOG.',
            'section' => 'DUMMY', 'no_tiang' => 'F.REOG.000777', 'naik_dari' => 'KABEL SR', 'terpasang_bh' => 0,
            'kurang_bh' => 4, 'status' => 'BELUM EKSEKUSI', 'koordinat' => '-5.2001,105.7001',
            'foto' => 'https://example.test/foto/A777.jpg', 'source_row_number' => 42,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.apkuk.show', $id))->assertOk();

        foreach (['ID', 'Tanggal', 'Petugas', 'Penyulang', 'Section', 'No. Tiang/Gardu', 'Naik Dari', 'Terpasang bh', 'Kurang bh', 'Status', 'Koordinat', 'Foto'] as $header) {
            $response->assertSee($header);
        }
        foreach (['A777', '15/09/2026', 'Petugas Uji SPS', 'REOG.', 'DUMMY', 'F.REOG.000777', 'KABEL SR', 'BELUM EKSEKUSI', '-5.2001,105.7001', 'https://example.test/foto/A777.jpg'] as $value) {
            $response->assertSee($value);
        }
        foreach (range('A', 'L') as $column) {
            $response->assertSee('data-source-column="'.$column.'"', false);
        }
        // Nol adalah nilai sumber, bukan kosong.
        $this->assertStringNotContainsString('Kosong di sumber', $this->column($response->getContent(), 'H'));
        $response->assertSee('Aktif di sumber')->assertSee('Dinormalisasi dari');
        $this->assertSame('DUMMY', DB::table('apkuks')->where('id', $id)->value('section'), 'Nilai sumber tidak diubah.');
    }

    public function test_empty_source_cells_are_shown_as_empty_not_fabricated(): void
    {
        $id = $this->apkuk(['petugas' => null, 'naik_dari' => null, 'koordinat' => null, 'foto' => null, 'latitude' => null, 'longitude' => null, 'kurang_bh' => null, 'section' => null]);
        $html = $this->actingAs($this->admin)->get(route('admin.apkuk.show', $id))->assertOk()->getContent();

        foreach (['C', 'E', 'G', 'I', 'K', 'L'] as $column) {
            $this->assertStringContainsString('Kosong di sumber', $this->column($html, $column), "Kolom {$column}");
        }
        foreach (['A', 'B', 'D', 'F', 'H', 'J'] as $column) {
            $this->assertStringNotContainsString('Kosong di sumber', $this->column($html, $column), "Kolom {$column}");
        }
        $this->assertStringContainsString('Tidak ada (kolom K kosong)', $html);
    }

    public function test_detail_reflects_pending_and_history_state_and_related_work_order(): void
    {
        $active = $this->apkuk(['no_tiang' => 'F.REOG.100001']);
        $wo = $this->createApkukWorkOrder($active);
        $older = $this->apkuk(['no_tiang' => 'F.REOG.100001', 'tanggal' => '2026-08-01', 'source_id' => 'LAMA1', 'source_missing_at' => now()]);
        $pending = $this->apkuk(['no_tiang' => 'F.REOG.100002']);
        ApkukReconciliationItem::create([
            'apkuk_id' => $pending, 'kind' => ApkukReconciliationItem::KIND_MISSING, 'status' => ApkukReconciliationItem::STATUS_OPEN,
            'legacy_key' => 'uji-pending', 'source_record' => ['source_id' => 'X'], 'first_detected_at' => now(), 'last_detected_at' => now(), 'detection_count' => 1,
        ]);

        $this->actingAs($this->admin)->get(route('admin.apkuk.show', $active))->assertOk()
            ->assertSee('Aktif di sumber')->assertSee($wo->code)->assertSee('LAMA1')->assertSee('Histori');
        $this->actingAs($this->admin)->get(route('admin.apkuk.show', $older))->assertOk()
            ->assertSee('Histori (diarsipkan)')->assertSee('Laporan histori tidak dinilai');
        $this->actingAs($this->admin)->get(route('admin.apkuk.show', $pending))->assertOk()
            ->assertSee('Menunggu rekonsiliasi')->assertSee('Hilang dari sumber')->assertSee('Terbuka');
    }

    public function test_list_links_to_detail(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.200001']);

        $this->actingAs($this->admin)->get(route('admin.apkuk.index'))->assertOk()
            ->assertSee(route('admin.apkuk.show', $id), false);
    }

    public function test_only_active_admin_can_open_detail_and_unknown_ids_are_404(): void
    {
        $id = $this->apkuk();

        $this->get(route('admin.apkuk.show', $id))->assertRedirect(route('login'));
        $this->actingAs($this->fieldAccount($this->poskoTeam, 'posko_web'))->get(route('admin.apkuk.show', $id))->assertForbidden();
        auth()->forgetGuards();
        $this->actingAs($this->admin)->get('/admin/apkuk/999999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/apkuk/bukan-angka')->assertNotFound();
    }

    private function column(string $html, string $column): string
    {
        $this->assertSame(1, preg_match('/data-source-column="'.$column.'">(.*?)<\/div>/s', $html, $match), "Kolom {$column} tidak ditemukan");

        return $match[1];
    }
}
