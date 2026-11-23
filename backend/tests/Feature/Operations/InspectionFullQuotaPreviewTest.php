<?php

namespace Tests\Feature\Operations;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** V6B1 HTTP read-only test. Never invokes legacy generator or pilot write method. */
class InspectionFullQuotaPreviewTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 10, 0, 0, ShiftService::TIMEZONE));
    }

    public function test_admin_can_view_inspection_full_candidates_without_any_generation_writes(): void
    {
        DB::table('section_pic_source_rows')->insert([
            'penyulang_raw' => 'REOG',
            'pemutus_raw' => 'SEC PUGUNG V6B1',
            'posko_pic_raw' => 'PUGUNG',
            'source_row_number' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $inspection = Inspection::create([
            'code' => 'INS-V6B1-001',
            'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'section_name' => 'SEC PUGUNG V6B1',
            'visited_at' => now()->subDay(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
            'submitted_by_user_id' => $this->admin->getKey(),
        ]);
        InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'APKUK_PREVIEW',
            'description' => 'Kebutuhan uji pratinjau',
            'suggested_team_type' => WorkOrder::TEAM_POSKO,
            'apkuk_no_tiang' => 'F.PREVIEW.001',
            'apkuk_target_quantity' => 3,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
            'validated_by_user_id' => $this->admin->getKey(),
            'validated_at' => now(),
        ]);
        $url = route('admin.shift-quota.inspection-full', [
            'operational_date' => '2026-11-23', 'shift_code' => 'A',
        ]);
        $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertSee('F.PREVIEW.001')
            ->assertSee('Pratinjau kandidat INSPEKSI')
            ->assertSee('1 / 6');

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, DB::table('shift_instances')->count());
        $this->assertSame(0, DB::table('apkuk_generation_runs')->count());
        $this->assertSame(0, DB::table('apkuk_shift_allocations')->count());
    }

    public function test_preview_route_is_admin_only_and_fail_closed_for_nonlocal_database(): void
    {
        $url = route('admin.shift-quota.inspection-full');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->fieldAccount($this->poskoTeam, 'FIELD_V6B1'))
            ->get($url)->assertForbidden();

        $this->app['auth']->forgetGuards();
        config(['database.connections.sqlite.database' => 'non-memory.sqlite']);
        $this->actingAs($this->admin)->get($url)->assertNotFound();
        $this->assertSame(0, DB::table('work_orders')->count());
    }
}
