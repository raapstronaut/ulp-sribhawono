<?php

namespace Tests\Unit\Inspection;

use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Inspection\ApprovedFindingWorkOrderService;
use Tests\TestCase;

/** Unit rules only; full DB/HTTP integration tests are required before rollout. */
class ApprovedFindingWorkOrderServiceTest extends TestCase
{
    public function test_only_approved_unlinked_finding_may_receive_work_order(): void
    {
        $finding = new InspectionFinding([
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
        ]);
        $this->assertTrue(ApprovedFindingWorkOrderService::isUnlinkedApproved($finding));

        $finding->validation_status = InspectionFinding::VALIDATION_MENUNGGU;
        $this->assertFalse(ApprovedFindingWorkOrderService::isUnlinkedApproved($finding));

        $finding->validation_status = InspectionFinding::VALIDATION_DITOLAK;
        $this->assertFalse(ApprovedFindingWorkOrderService::isUnlinkedApproved($finding));

        $finding->validation_status = InspectionFinding::VALIDATION_DISETUJUI;
        $finding->follow_up_work_order_id = '11111111-1111-4111-8111-111111111111';
        $this->assertFalse(ApprovedFindingWorkOrderService::isUnlinkedApproved($finding));
    }

    public function test_only_valid_harsus_or_row_work_types_are_supported(): void
    {
        $this->assertTrue(ApprovedFindingWorkOrderService::supportsPlan(WorkOrder::TEAM_HARSUS, WorkOrder::TYPE_PEKERJAAN_KHUSUS));
        foreach (WorkOrder::ROW_WORK_TYPES as $type) {
            $this->assertTrue(ApprovedFindingWorkOrderService::supportsPlan(WorkOrder::TEAM_ROW, $type));
        }
        $this->assertFalse(ApprovedFindingWorkOrderService::supportsPlan(WorkOrder::TEAM_POSKO, WorkOrder::TYPE_PEMASANGAN_APKUK));
        $this->assertFalse(ApprovedFindingWorkOrderService::supportsPlan(WorkOrder::TEAM_HARSUS, WorkOrder::TYPE_PANGKAS));
        $this->assertFalse(ApprovedFindingWorkOrderService::supportsPlan(WorkOrder::TEAM_ROW, WorkOrder::TYPE_PEKERJAAN_KHUSUS));
        $this->assertFalse(ApprovedFindingWorkOrderService::supportsPlan('UNKNOWN', WorkOrder::TYPE_PANGKAS));
    }
}
