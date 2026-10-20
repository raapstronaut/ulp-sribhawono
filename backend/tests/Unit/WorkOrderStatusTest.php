<?php

namespace Tests\Unit;

use App\Enums\WorkOrderStatus;
use PHPUnit\Framework\TestCase;

class WorkOrderStatusTest extends TestCase
{
    public function test_terminal_statuses_release_target_and_allow_no_transition(): void
    {
        foreach ([WorkOrderStatus::Selesai, WorkOrderStatus::SelesaiDiteruskan, WorkOrderStatus::Dibatalkan] as $status) {
            $this->assertTrue($status->isTerminal());
            $this->assertFalse($status->isActive());
            $this->assertSame([], $status->allowedTransitions());
        }
    }

    public function test_installation_report_completes_without_admin_verification(): void
    {
        $this->assertTrue(WorkOrderStatus::Ditugaskan->canTransitionTo(WorkOrderStatus::Selesai));
        $this->assertTrue(WorkOrderStatus::Dikerjakan->canTransitionTo(WorkOrderStatus::Selesai));
    }

    public function test_already_complete_finding_waits_for_admin_verification(): void
    {
        $this->assertTrue(WorkOrderStatus::Dikerjakan->canTransitionTo(WorkOrderStatus::MenungguVerifikasi));
        $this->assertTrue(WorkOrderStatus::MenungguVerifikasi->isActive());
        $this->assertTrue(WorkOrderStatus::MenungguVerifikasi->canTransitionTo(WorkOrderStatus::Selesai));
        $this->assertTrue(WorkOrderStatus::MenungguVerifikasi->canTransitionTo(WorkOrderStatus::Ditugaskan));
        $this->assertFalse(WorkOrderStatus::MenungguVerifikasi->canTransitionTo(WorkOrderStatus::RescheduleDiajukan));
    }

    public function test_reschedule_request_needs_admin_decision(): void
    {
        $this->assertTrue(WorkOrderStatus::Ditugaskan->canTransitionTo(WorkOrderStatus::RescheduleDiajukan));
        $this->assertTrue(WorkOrderStatus::RescheduleDiajukan->canTransitionTo(WorkOrderStatus::DijadwalkanUlang));
        $this->assertFalse(WorkOrderStatus::RescheduleDiajukan->canTransitionTo(WorkOrderStatus::Selesai));
        $this->assertTrue(WorkOrderStatus::DijadwalkanUlang->canTransitionTo(WorkOrderStatus::Dikerjakan));
    }

    public function test_active_list_matches_non_terminal_statuses(): void
    {
        $this->assertSame([
            WorkOrderStatus::Ditugaskan,
            WorkOrderStatus::Dikerjakan,
            WorkOrderStatus::MenungguVerifikasi,
            WorkOrderStatus::RescheduleDiajukan,
            WorkOrderStatus::DijadwalkanUlang,
        ], WorkOrderStatus::active());
    }
}
