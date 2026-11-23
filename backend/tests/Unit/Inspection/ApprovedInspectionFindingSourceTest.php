<?php

namespace Tests\Unit\Inspection;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Inspection\ApprovedInspectionFindingSource;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tes kebijakan antrean tanpa menyentuh database. Tes query SQL/integrasi
 * dan validasi target WO diperlukan sebelum generator universal diaktifkan.
 */
class ApprovedInspectionFindingSourceTest extends TestCase
{
    private function finding(?string $suggestedTeamType, string $inspectionType = Inspection::TYPE_FULL): InspectionFinding
    {
        $finding = new InspectionFinding([
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
            'suggested_team_type' => $suggestedTeamType,
        ]);
        $finding->setRelation('inspection', new Inspection(['inspection_type' => $inspectionType]));

        return $finding;
    }

    public function test_approved_unlinked_findings_from_all_teams_are_kept_for_review(): void
    {
        foreach ([WorkOrder::TEAM_POSKO, WorkOrder::TEAM_ROW, WorkOrder::TEAM_HARSUS, null] as $suggestion) {
            $this->assertTrue(ApprovedInspectionFindingSource::matches($this->finding($suggestion)));
        }
    }

    public function test_pending_rejected_and_already_linked_findings_are_not_in_queue(): void
    {
        $finding = $this->finding(WorkOrder::TEAM_HARSUS);
        $finding->validation_status = InspectionFinding::VALIDATION_MENUNGGU;
        $this->assertFalse(ApprovedInspectionFindingSource::matches($finding));

        $finding->validation_status = InspectionFinding::VALIDATION_DITOLAK;
        $this->assertFalse(ApprovedInspectionFindingSource::matches($finding));

        $finding->validation_status = InspectionFinding::VALIDATION_DISETUJUI;
        $finding->follow_up_work_order_id = '6ff0aa31-666a-470d-8e3f-691d1f40c211';
        $this->assertFalse(ApprovedInspectionFindingSource::matches($finding));
    }

    public function test_team_filter_uses_suggestion_and_full_only_is_explicit(): void
    {
        $harsus = $this->finding(WorkOrder::TEAM_HARSUS, Inspection::TYPE_REGULAR);
        $this->assertTrue(ApprovedInspectionFindingSource::matches($harsus, WorkOrder::TEAM_HARSUS));
        $this->assertFalse(ApprovedInspectionFindingSource::matches($harsus, WorkOrder::TEAM_ROW));
        $this->assertFalse(ApprovedInspectionFindingSource::matches($harsus, null, true));

        $full = $this->finding(WorkOrder::TEAM_ROW);
        $this->assertTrue(ApprovedInspectionFindingSource::matches($full, WorkOrder::TEAM_ROW, true));
    }

    public function test_unknown_team_filter_is_rejected_not_silently_assigned(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApprovedInspectionFindingSource::matches($this->finding(null), 'LAINNYA');
    }
}
