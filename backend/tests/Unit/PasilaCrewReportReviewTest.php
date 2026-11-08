<?php

namespace Tests\Unit;

use App\Services\Pasila\PasilaCrewReportReview;
use App\Services\Pasila\PoskoCrewPreview;
use App\Services\PoskoCrewRotationService;
use App\Services\ShiftService;
use PHPUnit\Framework\TestCase;

class PasilaCrewReportReviewTest extends TestCase
{
    private function auditor(): PasilaCrewReportReview
    {
        return new PasilaCrewReportReview(
            new PoskoCrewPreview(new PoskoCrewRotationService(new ShiftService)),
        );
    }

    /** @return array<string, mixed> */
    private function schedule(): array
    {
        return [
            'status' => 'ready',
            'crew_number' => 1,
            'members' => [['id' => 'person-1', 'name' => 'Anung'], ['id' => 'person-2', 'name' => 'Riris']],
        ];
    }

    public function test_matching_two_person_crew_in_any_order(): void
    {
        $auditor = $this->auditor();
        $this->assertSame('match', $auditor->assess($this->schedule(), ['person-1', 'person-2'])['status']);
        $this->assertSame('match', $auditor->assess($this->schedule(), ['person-2', 'person-1'])['status']);
    }

    public function test_one_scheduled_member_is_partial_not_a_violation(): void
    {
        $this->assertSame('partial', $this->auditor()->assess($this->schedule(), ['person-1'])['status']);
    }

    public function test_other_crew_member_and_extra_member_require_review(): void
    {
        $auditor = $this->auditor();
        $this->assertSame('different', $auditor->assess($this->schedule(), ['person-3'])['status']);
        $this->assertSame('different', $auditor->assess($this->schedule(), ['person-1', 'person-3'])['status']);
        $this->assertSame('different', $auditor->assess($this->schedule(), ['person-1', 'person-2', 'person-3'])['status']);
    }

    public function test_missing_personnel_or_unavailable_roster_cannot_be_verified(): void
    {
        $auditor = $this->auditor();
        $this->assertSame('unverifiable', $auditor->assess($this->schedule(), [])['status']);
        $this->assertSame('unverifiable', $auditor->assess(['status' => 'unavailable', 'members' => []], ['person-1'])['status']);
        $this->assertSame('unverifiable', $auditor->assess(['status' => 'ready', 'members' => [['id' => 'person-1']]], ['person-1'])['status']);
    }

    public function test_audit_summarizes_findings_nil_and_closure_without_blocking(): void
    {
        $result = $this->auditor()->reviewEntries($this->schedule(), [
            ['label' => 'Temuan #1', 'personnel_ids' => ['person-1', 'person-2'], 'personnel_names' => ['Anung', 'Riris']],
            ['label' => 'Laporan nihil', 'personnel_ids' => ['person-1'], 'personnel_names' => ['Anung']],
            ['label' => 'Penutupan sesi', 'personnel_ids' => ['person-3'], 'personnel_names' => ['Petugas pengganti']],
            ['label' => 'Temuan #2', 'personnel_ids' => [], 'personnel_names' => []],
        ]);
        $this->assertSame(['match' => 1, 'partial' => 1, 'different' => 1, 'unverifiable' => 1], $result['counts']);
        $this->assertCount(4, $result['rows']);
        $this->assertSame('Perlu tinjauan', $result['rows'][2]['status_label']);
        $this->assertSame('Temuan #1', $result['rows'][0]['label']);
    }
}
