<?php

namespace Tests\Feature\Operations;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Services\ApkukCandidateService;
use App\Services\Inspection\InspectionApkukWorkOrderService;
use App\Services\ShiftService;
use App\Services\Work\WorkflowConflictException;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Integration test: HTTP Admin + SQLite in-memory, no local PostgreSQL writes. */
class InspectionFullApkukWorkOrderTest extends OperationsTestCase
{
    private int $serial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 10, 0, 0, ShiftService::TIMEZONE));
    }

    public function test_admin_can_create_manual_posko_wo_from_approved_full_finding_without_sps_record(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $response = $this->actingAs($this->admin)->post(route('admin.inspeksi.findings.posko-work-order', $finding), $this->input());
        $response->assertRedirect(route('admin.inspeksi.show', $finding->inspection_id))->assertSessionHasNoErrors();

        $id = $finding->fresh()->follow_up_work_order_id;
        $this->assertNotNull($id);
        $wo = WorkOrder::query()->findOrFail($id);
        $this->assertSame(WorkOrder::TEAM_POSKO, $wo->team_type);
        $this->assertSame(WorkOrder::TYPE_PEMASANGAN_APKUK, $wo->work_type);
        $this->assertSame(WorkOrder::SOURCE_ADMIN_MANUAL, $wo->source);
        $this->assertSame($this->posko->getKey(), $wo->posko_id);
        $this->assertSame('2026-11-23', $wo->work_date->toDateString());
        $this->assertSame('A', $wo->shift_code);
        $this->assertNull($wo->generation_run_id);

        $target = WorkOrderApkukTarget::query()->where('work_order_id', $wo->getKey())->sole();
        $this->assertNull($target->apkuk_id, 'INSPEKSI must not forge SPS IDs');
        $this->assertSame($finding->getKey(), $target->inspection_finding_id);
        $this->assertSame(2, $target->target_quantity);
        $this->assertSame('F.REOG.123', $target->no_tiang);
        $this->assertSame(ApkukCandidateService::targetKey($this->penyulang->kode_penyulang, 'F.REOG.123'), $target->active_target_key);
        $this->assertSame('F.REOG.123', $wo->fresh()->targetLabel());
        $this->assertSame([-5.1234567, 105.1234567], $wo->fresh()->targetCoordinates());
        $log = $wo->statusLogs()->firstOrFail()->payload;
        $this->assertSame($finding->getKey(), $log['inspection_finding_id']);
        $this->assertSame('INSPEKSI', $log['origin']);
    }

    public function test_same_finding_is_not_created_twice(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $service = app(InspectionApkukWorkOrderService::class);
        $service->createManual($finding, $this->admin, $this->input());
        try {
            $service->createManual($finding, $this->admin, $this->input());
            $this->fail('WO duplicate should have been rejected.');
        } catch (WorkflowConflictException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_pending_is_rejected_but_approved_legacy_umum_and_full_are_both_valid_sources(): void
    {
        $pending = $this->finding(InspectionFinding::VALIDATION_MENUNGGU, Inspection::TYPE_FULL);
        try {
            app(InspectionApkukWorkOrderService::class)->createManual($pending, $this->admin, $this->input());
            $this->fail('Pending finding should not create a POSKO APKUK WO.');
        } catch (WorkflowConflictException | ValidationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $approvedUmum = $this->finding(InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_REGULAR);
        app(InspectionApkukWorkOrderService::class)->createManual($approvedUmum, $this->admin, $this->input());
        $this->assertNotNull($approvedUmum->fresh()->follow_up_work_order_id);
        // The old FULL value also remains supported for historical records. Use a distinct pole.
        $approvedFull = $this->finding(InspectionFinding::VALIDATION_DISETUJUI, Inspection::TYPE_FULL, 'F.REOG.LEGACY.FULL');
        app(InspectionApkukWorkOrderService::class)->createManual($approvedFull, $this->admin, $this->input());
        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_two_findings_for_same_pole_cannot_create_two_active_wo(): void
    {
        $first = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $second = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $service = app(InspectionApkukWorkOrderService::class);
        $service->createManual($first, $this->admin, $this->input());
        try {
            $service->createManual($second, $this->admin, $this->input());
            $this->fail('Second active target must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('finding', $e->errors());
        }
        $this->assertNull($second->fresh()->follow_up_work_order_id);
        $this->assertSame(1, WorkOrder::query()->count());
    }

    private function finding(string $status, string $inspectionType = Inspection::TYPE_REGULAR, string $pole = 'F.REOG.123'): InspectionFinding
    {
        $this->serial++;
        $inspection = Inspection::create([
            'code' => 'INS-V4-'.str_pad((string) $this->serial, 4, '0', STR_PAD_LEFT),
            'penyulang_id' => $this->penyulang->getKey(),
            'section_name' => 'SEC PUGUNG',
            'visited_at' => now(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => $inspectionType,
            'submitted_by_user_id' => $this->admin->getKey(),
        ]);
        return InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'APKUK_UJI',
            'description' => 'Kurang dua set APKUK pada tiang uji',
            'asset_label' => 'Tiang '.$pole,
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'suggested_team_type' => WorkOrder::TEAM_POSKO,
            'apkuk_no_tiang' => $pole,
            'apkuk_target_quantity' => 2,
            'validation_status' => $status,
            'validated_by_user_id' => $status === InspectionFinding::VALIDATION_DISETUJUI ? $this->admin->getKey() : null,
            'validated_at' => $status === InspectionFinding::VALIDATION_DISETUJUI ? now() : null,
        ]);
    }

    private function input(): array
    {
        return [
            'posko_id' => $this->posko->getKey(),
            'work_date' => '2026-11-23',
            'shift_code' => 'A',
        ];
    }
}
