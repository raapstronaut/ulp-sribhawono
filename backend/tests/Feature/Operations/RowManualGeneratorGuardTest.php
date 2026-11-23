<?php

namespace Tests\Feature\Operations;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Inspection\ApprovedFindingWorkOrderService;
use App\Services\Work\RowPointConflictGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared ROW guard regression on isolated SQLite :memory: (not PostgreSQL live). */
class RowManualGeneratorGuardTest extends OperationsTestCase
{
    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-22 10:00:00', 'Asia/Jakarta'));
    }

    public function test_row_manual_rejects_same_point_even_when_work_subtype_differs(): void
    {
        $first = $this->finding(-5.123456, 105.123456);
        $second = $this->finding(-5.123456, 105.123456);
        $service = app(ApprovedFindingWorkOrderService::class);
        $one = $service->create($first, $this->admin, $this->input(WorkOrder::TYPE_PANGKAS));
        $this->assertSame(WorkOrder::SOURCE_ADMIN_MANUAL, $one->source);

        try {
            $service->create($second, $this->admin, $this->input(WorkOrder::TYPE_RABAS));
            $this->fail('Pekerjaan ROW berbeda pada koordinat sama tidak boleh berjalan paralel.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target', $e->errors());
        }
        $this->assertNull($second->fresh()->follow_up_work_order_id);
        $this->assertSame(1, WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)->count());
    }

    public function test_row_manual_allows_different_point_after_cross_subtype_collision(): void
    {
        $service = app(ApprovedFindingWorkOrderService::class);
        $first = $this->finding(-5.123456, 105.123456);
        $other = $this->finding(-5.123999, 105.123999);
        $service->create($first, $this->admin, $this->input(WorkOrder::TYPE_PANGKAS));
        $second = $service->create($other, $this->admin, $this->input(WorkOrder::TYPE_TEBANG));
        $this->assertSame((string) $second->getKey(), (string) $other->fresh()->follow_up_work_order_id);
        $this->assertSame(2, WorkOrder::query()->where('team_type', WorkOrder::TEAM_ROW)->count());
    }

    public function test_sqlite_test_does_not_trigger_postgresql_advisory_sql(): void
    {
        DB::transaction(function (): void {
            app(RowPointConflictGuard::class)->lock();
        });
        $this->assertSame(0, WorkOrder::query()->count());
    }

    private function finding(float $latitude, float $longitude): InspectionFinding
    {
        $this->serial++;
        $inspection = Inspection::create([
            'code' => 'ROW-V16-GUARD-'.str_pad((string) $this->serial, 3, '0', STR_PAD_LEFT),
            'field_team_id' => $this->inspeksiTeam->getKey(),
            'penyulang_id' => $this->penyulang->getKey(),
            'section_name' => 'TITIK UJI ROW V16',
            'visited_at' => now(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
        ]);
        return InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'POHON_DEKAT',
            'description' => 'Temuan sintetis ROW v16',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ]);
    }

    private function input(string $workType): array
    {
        return [
            'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => $workType,
            'field_team_id' => $this->rowTeam->getKey(),
            'work_date' => '2026-11-23',
            'due_date' => '2026-11-24',
        ];
    }
}
