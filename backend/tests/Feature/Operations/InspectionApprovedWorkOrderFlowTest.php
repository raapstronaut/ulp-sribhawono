<?php

namespace Tests\Feature\Operations;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Services\Inspection\ApprovedFindingWorkOrderService;
use App\Services\ShiftService;
use App\Services\Work\WorkflowConflictException;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tes integrasi v2: HTTP Admin -> temuan INSPEKSI -> WO ROW, dengan DB SQLite in-memory.
 * Tidak menguji/menjalankan generator atau membuat WO di database operasional.
 */
class InspectionApprovedWorkOrderFlowTest extends OperationsTestCase
{
    private int $inspectionSerial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 10, 0, 0, ShiftService::TIMEZONE));
    }

    public function test_admin_creates_row_work_order_from_approved_finding_and_preserves_provenance(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $input = $this->rowInput();

        $response = $this->actingAs($this->admin)->post(
            route('admin.inspeksi.findings.work-order', $finding),
            $input
        );

        $response->assertRedirect(route('admin.inspeksi.show', $finding->inspection_id));
        $response->assertSessionHasNoErrors();

        $workOrderId = $finding->fresh()->follow_up_work_order_id;
        $this->assertNotNull($workOrderId, 'Temuan disetujui harus terhubung ke WO nyata.');

        $wo = WorkOrder::query()->findOrFail($workOrderId);
        $this->assertSame(WorkOrder::TEAM_ROW, $wo->team_type);
        $this->assertSame(WorkOrder::TYPE_PANGKAS, $wo->work_type);
        $this->assertSame(WorkOrder::SOURCE_ADMIN_MANUAL, $wo->source);

        $target = WorkOrderLocationTarget::query()->where('work_order_id', $wo->getKey())->sole();
        $this->assertSame(WorkOrderLocationTarget::TYPE_INSPEKSI, $target->target_type);
        $this->assertSame((string) $finding->getKey(), (string) $target->source_ref);
        $this->assertSame('INSPEKSI:'.$finding->getKey(), $target->target_key);
        $this->assertSame((string) $finding->getKey(), (string) ($target->snapshot['inspection_finding_id'] ?? ''));

        $payload = $wo->statusLogs()->firstOrFail()->payload;
        $this->assertSame((string) $finding->getKey(), (string) ($payload['inspection_finding_id'] ?? ''));
        $this->assertSame(WorkOrder::TEAM_ROW, $payload['assigned_team_type'] ?? null);
    }

    public function test_same_finding_cannot_create_second_work_order(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $service = app(ApprovedFindingWorkOrderService::class);
        $input = $this->serviceInput();
        $first = $service->create($finding, $this->admin, $input);

        try {
            $service->create($finding->fresh(), $this->admin, $input);
            $this->fail('Dua WO dari satu temuan seharusnya ditolak.');
        } catch (WorkflowConflictException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame((string) $first->getKey(), (string) $finding->fresh()->follow_up_work_order_id);
        $this->assertSame(1, WorkOrder::query()->where('id', $first->getKey())->count());
        $this->assertSame(1, WorkOrderLocationTarget::query()->where('source_ref', $finding->getKey())->count());
    }

    public function test_approved_row_finding_cannot_be_assigned_to_harsus_even_with_valid_keypoint(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $finding->forceFill(['keypoint_id' => $this->keypoint()])->save();
        $input = $this->serviceInput();
        $input['team_type'] = WorkOrder::TEAM_HARSUS;
        $input['work_type'] = WorkOrder::TYPE_PEKERJAAN_KHUSUS;

        try {
            app(ApprovedFindingWorkOrderService::class)->create($finding, $this->admin, $input);
            $this->fail('Temuan ROW tidak boleh berubah menjadi WO HARSUS tanpa penggantian tim tujuan yang sah.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('follow_up', $e->errors());
            $this->assertStringContainsString('tim tujuan', $e->errors()['follow_up'][0]);
        }
        $this->assertNull($finding->fresh()->follow_up_work_order_id);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_acc_row_finding_cannot_create_harsus_work_order(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_MENUNGGU);
        $finding->forceFill(['keypoint_id' => $this->keypoint()])->save();
        $input = $this->serviceInput();
        $input['team_type'] = WorkOrder::TEAM_HARSUS;
        $input['work_type'] = WorkOrder::TYPE_PEKERJAAN_KHUSUS;

        try {
            app(\App\Services\Inspection\InspectionService::class)->approveFinding($finding, $this->admin, null, $input);
            $this->fail('ACC tidak boleh menerbitkan WO untuk tim yang berbeda dari pilihan INSPEKSI.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('follow_up_team_type', $e->errors());
            $this->assertStringContainsString('tim tujuan', $e->errors()['follow_up_team_type'][0]);
        }
        $this->assertSame(InspectionFinding::VALIDATION_MENUNGGU, $finding->fresh()->validation_status);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_pending_finding_cannot_create_work_order(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_MENUNGGU);

        try {
            app(ApprovedFindingWorkOrderService::class)->create($finding, $this->admin, $this->serviceInput());
            $this->fail('Temuan pending tidak boleh menjadi WO.');
        } catch (WorkflowConflictException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertNull($finding->fresh()->follow_up_work_order_id);
        $this->assertSame(0, WorkOrderLocationTarget::query()->where('source_ref', $finding->getKey())->count());
    }

    public function test_harsus_requires_verified_keypoint(): void
    {
        $finding = $this->finding(InspectionFinding::VALIDATION_DISETUJUI);
        $input = $this->serviceInput();
        $input['team_type'] = WorkOrder::TEAM_HARSUS;
        $input['work_type'] = WorkOrder::TYPE_PEKERJAAN_KHUSUS;

        try {
            app(ApprovedFindingWorkOrderService::class)->create($finding, $this->admin, $input);
            $this->fail('HARSUS tanpa keypoint wajib ditolak oleh kebijakan yang berlaku.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('follow_up', $e->errors());
        }

        $this->assertNull($finding->fresh()->follow_up_work_order_id);
    }

    private function finding(string $status): InspectionFinding
    {
        $this->inspectionSerial++;
        $inspection = Inspection::create([
            'code' => 'INS-V3-'.str_pad((string) $this->inspectionSerial, 4, '0', STR_PAD_LEFT),
            'field_team_id' => null,
            'penyulang_id' => $this->penyulang->getKey(),
            'section_name' => 'AREA UJI V3',
            'visited_at' => now(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
            'submitted_by_user_id' => $this->admin->getKey(),
        ]);

        return InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'VEGETASI_UJI',
            'description' => 'Pohon mengganggu jaringan pada lokasi uji',
            'asset_label' => 'Pohon lokasi uji',
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'suggested_team_type' => WorkOrder::TEAM_ROW,
            'validation_status' => $status,
            'validated_by_user_id' => $status === InspectionFinding::VALIDATION_DISETUJUI ? $this->admin->getKey() : null,
            'validated_at' => $status === InspectionFinding::VALIDATION_DISETUJUI ? now() : null,
        ]);
    }

    /** @return array<string, string> */
    private function rowInput(): array
    {
        return [
            'follow_up' => WorkOrder::TEAM_ROW.'|'.WorkOrder::TYPE_PANGKAS,
            'work_date' => '2026-11-23',
            'due_date' => '2026-11-24',
        ];
    }

    /** @return array<string, string> */
    private function serviceInput(): array
    {
        return [
            'team_type' => WorkOrder::TEAM_ROW,
            'work_type' => WorkOrder::TYPE_PANGKAS,
            'work_date' => '2026-11-23',
            'due_date' => '2026-11-24',
        ];
    }
}
