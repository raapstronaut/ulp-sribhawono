<?php

namespace Tests\Unit\Inspection;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use Tests\TestCase;

/**
 * Tes aturan sumber tanpa query DB: data UMUM/pending bukan kandidat.
 * Query SQL dan migrasi akan diuji dalam tes integrasi fase berikutnya.
 */
class FullApkukFindingSourceTest extends TestCase
{
    public function test_only_approved_full_inspection_with_apkuk_target_is_eligible(): void
    {
        $finding = new InspectionFinding([
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
            'suggested_team_type' => WorkOrder::TEAM_POSKO,
            'apkuk_no_tiang' => 'F.REOG.123',
            'apkuk_target_quantity' => 2,
        ]);
        $finding->setRelation('inspection', new Inspection(['inspection_type' => Inspection::TYPE_FULL]));
        $this->assertTrue($finding->isApprovedFullApkukTarget());

        $finding->validation_status = InspectionFinding::VALIDATION_MENUNGGU;
        $this->assertFalse($finding->isApprovedFullApkukTarget());

        $finding->validation_status = InspectionFinding::VALIDATION_DISETUJUI;
        $finding->setRelation('inspection', new Inspection(['inspection_type' => Inspection::TYPE_REGULAR]));
        $this->assertFalse($finding->isApprovedFullApkukTarget());

        $finding->setRelation('inspection', new Inspection(['inspection_type' => Inspection::TYPE_FULL]));
        $finding->apkuk_target_quantity = null;
        $this->assertFalse($finding->isApprovedFullApkukTarget());

        $finding->apkuk_target_quantity = 1;
        $finding->suggested_team_type = WorkOrder::TEAM_HARSUS;
        $this->assertFalse($finding->isApprovedFullApkukTarget());
    }
}
