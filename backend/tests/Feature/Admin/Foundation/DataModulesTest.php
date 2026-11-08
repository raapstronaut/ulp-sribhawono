<?php

namespace Tests\Feature\Admin\Foundation;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusLog;
use Illuminate\Support\Facades\DB;

class DataModulesTest extends FoundationTestCase
{
    public function test_master_data_tabs_render_real_rows_read_only(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        Posko::create(['kode_posko' => 'TUTUP', 'nama_posko' => 'POSKO TUTUP', 'is_active' => false]);
        $penyulang = Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG', 'gardu_induk' => 'GI SRIBAWONO']);
        $posko->penyulangs()->attach($penyulang->id);
        $team = FieldTeam::create(['code' => 'ROW-1', 'name' => 'Tim ROW ULP', 'role' => 'ROW']);
        $team->personnel()->create(['name' => 'Budi Lapangan', 'personnel_code' => 'P-001']);
        DB::table('reference_codes')->insert([
            'id' => '0b5f8a2c-0000-4000-8000-000000000001',
            'category' => 'RESCHEDULE_REASON',
            'code' => 'CUACA',
            'label' => 'Cuaca buruk',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('keypoints')->insert([
            ['legacy_key' => str_repeat('a', 64), 'penyulang' => 'REOG', 'jenis' => 'RECLOSER', 'nama' => 'REC BRAJA', 'latitude' => -5.2, 'longitude' => 105.7, 'sumber_input' => 'SPS_BOS', 'created_at' => now(), 'updated_at' => now()],
            ['legacy_key' => str_repeat('b', 64), 'penyulang' => 'REOG', 'jenis' => 'FCO', 'nama' => 'FCO TANPA GPS', 'latitude' => null, 'longitude' => null, 'sumber_input' => 'SPS_BOS', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $expectations = [
            'posko' => ['PUGUNG', 'POSKO TUTUP', 'Nonaktif'],
            'penyulang' => ['REOG', 'GI SRIBAWONO'],
            'tim' => ['Tim ROW ULP', '1 / 1'],
            'petugas' => ['Budi Lapangan', 'P-001'],
            'kode' => ['RESCHEDULE_REASON', 'Cuaca buruk'],
            'keypoint' => ['REC BRAJA', 'Belum ada koordinat'],
            'sumber' => ['asset_source_rows', 'vegetasi_source_rows', 'Staging mentah', 'Sinkron sumber'],
        ];

        foreach ($expectations as $tab => $texts) {
            $response = $this->actingAs($this->admin)->get(route('admin.master-data.index', ['tab' => $tab]))->assertOk();
            foreach ($texts as $text) {
                $response->assertSee($text);
            }
            // Tim/petugas/kode dapat dikelola; Posko/Penyulang/Keypoint master resmi baca saja.
            if (in_array($tab, ['tim', 'petugas', 'kode'], true)) {
                $response->assertSee(route('admin.master-data.manage.create', $tab), false);
            } elseif ($tab !== 'sumber') {
                $response->assertSee('Master resmi')->assertDontSee(route('admin.master-data.manage.create', 'tim'), false);
            }
        }

        $this->actingAs($this->admin)
            ->get(route('admin.master-data.index', ['tab' => 'posko', 'status' => 'inactive']))
            ->assertOk()
            ->assertSee('POSKO TUTUP')
            ->assertDontSee(route('admin.master-data.posko.show', $posko), false);

        $this->actingAs($this->admin)
            ->get(route('admin.master-data.index', ['tab' => 'tim', 'role' => 'INSPEKSI']))
            ->assertOk()
            ->assertSee('Belum ada tim lapangan.');
    }

    public function test_master_data_detail_pages_show_relations_and_text_matched_source_counts(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $penyulang = Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);
        $posko->penyulangs()->attach($penyulang->id);
        FieldTeam::create(['code' => 'POSKO-PUGUNG', 'name' => 'Tim Pugung', 'role' => 'POSKO', 'posko_id' => $posko->id]);
        $this->makeWorkOrder(['posko_id' => $posko->id, 'penyulang_id' => $penyulang->id]);
        $this->makeGangguan('G-1', ['penyulang' => ' reog ']);
        $this->makeGangguan('G-2', ['penyulang' => 'MERAPI']);

        $this->actingAs($this->admin)
            ->get(route('admin.master-data.posko.show', $posko))
            ->assertOk()
            ->assertSee('Tim Pugung')
            ->assertSee('REOG')
            ->assertSee('1 / 1');

        $this->actingAs($this->admin)
            ->get(route('admin.master-data.penyulang.show', $penyulang))
            ->assertOk()
            ->assertSee('Pencocokan teks')
            ->assertSeeInOrder(['Gangguan (semua periode)', '1'])
            ->assertSee('PUGUNG');
    }

    public function test_analytics_aggregates_real_data_per_period(): void
    {
        $this->makeWorkOrder(['status' => WorkOrderStatus::Selesai]);
        $this->makeWorkOrder(['team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS]);
        $this->makeWorkOrder(['work_date' => '2025-01-01']);
        $this->makeGangguan('G-1', ['tanggal_kejadian' => '2026-10-15', 'penyebab' => 'POHON']);
        $this->makeGangguan('G-2', ['tanggal_kejadian' => '2026-09-15', 'penyebab' => 'POHON']);
        DB::table('apkuks')->insert([
            'legacy_key' => str_repeat('c', 64), 'penyulang' => 'REOG', 'no_tiang' => 'T1', 'status' => 'BELUM EKSEKUSI',
            'kurang_bh' => 3, 'sumber_input' => 'APPSHEET_LAMA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.analytics.index'))
            ->assertOk()
            ->assertSee('Work Order per tim dan status')
            ->assertSeeInOrder(['POSKO', '1', 'ROW'])
            ->assertSee('>10/2026</td>', false)
            ->assertSee('>09/2026</td>', false)
            ->assertSee('BELUM EKSEKUSI')
            ->assertSee('KPI PASILA, P0, Inspeksi &amp; ROW', false);

        $this->actingAs($this->admin)
            ->get(route('admin.analytics.index', ['period' => 30]))
            ->assertOk()
            ->assertSee('>10/2026</td>', false)
            ->assertDontSee('>09/2026</td>', false);
    }

    public function test_user_list_and_detail_never_expose_credentials(): void
    {
        $field = User::factory()->create(['username' => 'POSKO_PUGUNG', 'role' => 'POSKO', 'is_active' => true]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('FOUNDATION_ADMIN')
            ->assertSee('POSKO_PUGUNG')
            ->assertSee('API mobile v1')
            ->assertSee(route('admin.users.create'), false)
            ->getContent();
        $this->assertStringNotContainsString($field->getAuthPassword(), $html);
        $this->assertStringNotContainsString((string) $field->remember_token, $html);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'ADMIN']))
            ->assertOk()
            ->assertDontSee('POSKO_PUGUNG');

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $field))
            ->assertOk()
            ->assertSee('POSKO_PUGUNG')
            ->assertSee('Ditolak')
            ->assertDontSee($field->getAuthPassword());

        $this->actingAs($this->admin)->get('/admin/users/999999')->assertNotFound();
    }

    public function test_audit_log_lists_entries_with_filters_and_escapes_payload(): void
    {
        $wo = $this->makeWorkOrder(['code' => 'WO-AUDIT-1']);
        $row = $this->makeWorkOrder(['code' => 'WO-AUDIT-ROW', 'team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS]);
        WorkOrderStatusLog::create([
            'work_order_id' => $wo->id,
            'action' => WorkOrderStatusLog::ACTION_CREATED,
            'to_status' => 'DITUGASKAN',
            'actor_user_id' => $this->admin->id,
            'payload' => ['catatan' => '<script>alert(1)</script>'],
        ]);
        $cancel = WorkOrderStatusLog::create([
            'work_order_id' => $wo->id,
            'action' => WorkOrderStatusLog::ACTION_CANCELLED,
            'from_status' => 'DITUGASKAN',
            'to_status' => 'DIBATALKAN',
            'actor_user_id' => $this->admin->id,
            'reason' => 'Duplikat laporan',
        ]);
        WorkOrderStatusLog::create(['work_order_id' => $row->id, 'action' => WorkOrderStatusLog::ACTION_CREATED]);

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('WO-AUDIT-1')
            ->assertSee('Duplikat laporan')
            ->assertSee('FOUNDATION_ADMIN')
            ->assertSee('Sistem');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['action' => WorkOrderStatusLog::ACTION_CANCELLED]))
            ->assertOk()
            ->assertSee('Duplikat laporan')
            ->assertDontSee('WO-AUDIT-ROW');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['team_type' => WorkOrder::TEAM_ROW]))
            ->assertOk()
            ->assertSee('WO-AUDIT-ROW')
            ->assertDontSee('Duplikat laporan');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['date_from' => '2026-10-21']))
            ->assertOk()
            ->assertSee('Belum ada entri audit.');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.show', $cancel))
            ->assertOk()
            ->assertSee('Dibatalkan')
            ->assertSee('DITUGASKAN → DIBATALKAN')
            ->assertSee('Duplikat laporan');

        $created = WorkOrderStatusLog::query()->where('action', WorkOrderStatusLog::ACTION_CREATED)->where('work_order_id', $wo->id)->sole();
        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.show', $created))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
