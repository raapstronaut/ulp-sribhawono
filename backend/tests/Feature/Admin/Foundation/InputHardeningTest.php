<?php

namespace Tests\Feature\Admin\Foundation;

use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;

/**
 * Filter query string yang tidak valid diabaikan, bukan diteruskan ke SQL.
 * Di PostgreSQL nilai tersebut (UUID/tanggal tidak valid) memicu error;
 * SQLite hanya mengembalikan 0 baris, jadi test membuktikan filter diabaikan.
 */
class InputHardeningTest extends FoundationTestCase
{
    public function test_invalid_uuid_and_date_filters_are_ignored_instead_of_reaching_sql(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $this->makeWorkOrder(['code' => 'WO-APKUK-SATU', 'posko_id' => $posko->id]);
        $row = $this->makeWorkOrder(['code' => 'WO-ROW-SATU', 'team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS]);
        WorkOrderReport::create([
            'work_order_id' => $row->id,
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'verification_status' => WorkOrderReport::VERIFICATION_MENUNGGU,
        ]);

        $expectations = [
            route('admin.row.index', ['penyulang_id' => 'bukan-uuid', 'work_date' => '2026-13-45']) => 'WO-ROW-SATU',
            route('admin.reviews.index', ['posko_id' => 'bukan-uuid']) => 'WO-ROW-SATU',
            route('admin.work-orders.index', ['posko_id' => 'bukan-uuid', 'work_date' => '2026-02-31']) => 'WO-APKUK-SATU',
            route('admin.pasila.index', ['posko_id' => 'bukan-uuid']) => 'PUGUNG',
        ];

        foreach ($expectations as $url => $text) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee($text)->assertDontSee('Data tidak dapat dimuat');
        }
    }

    public function test_array_query_parameters_never_cause_server_errors(): void
    {
        $this->makeWorkOrder(['code' => 'WO-APKUK-SATU']);

        $urls = [
            '/admin/row?status[]=x&q[]=y',
            '/admin/harsus?source[]=x',
            '/admin/review?category[]=x&team_type[]=y',
            '/admin/pasila?shift_code[]=A&work_date[]=x',
            '/admin/pasila/'.(Posko::create(['kode_posko' => 'P', 'nama_posko' => 'P'])->id).'?shift_code[]=A',
            '/admin/special-case?dimension[]=x&period[]=1&q[]=z',
            '/admin/special-case/detail?key[]=x',
            '/admin/master-data?tab[]=x&q[]=y',
            '/admin/master-data?tab=keypoint&penyulang[]=x&jenis[]=y',
            '/admin/analitik?period[]=1',
            '/admin/users?role[]=x&status[]=y',
            '/admin/audit-log?date_from[]=x&team_type[]=y&action[]=z',
            '/admin/work-orders?status[]=x&posko_id[]=y',
            '/admin/work-orders/create?q[]=x&penyulang_id[]=y',
        ];

        foreach ($urls as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_numeric_ids_beyond_bigint_return_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/users/99999999999999999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/audit-log/99999999999999999999')->assertNotFound();
    }
}
