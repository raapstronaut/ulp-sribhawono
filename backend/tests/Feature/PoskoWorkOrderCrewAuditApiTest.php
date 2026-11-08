<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReportPersonnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoskoWorkOrderCrewAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkOrder(string $type = WorkOrder::TYPE_PEMASANGAN_APKUK): array
    {
        config()->set('field_ops.posko_crew_anchor_date', '2026-11-09');

        $posko = Posko::create([
            'kode_posko' => 'TEST_JABUNG',
            'nama_posko' => 'TEST JABUNG',
            'is_active' => true,
        ]);
        $team = FieldTeam::create([
            'code' => 'TEST_POSKO_JABUNG',
            'name' => 'TEST POSKO JABUNG',
            'role' => 'POSKO',
            'posko_id' => $posko->getKey(),
            'is_active' => true,
        ]);
        $persons = [];
        for ($i = 1; $i <= 8; $i++) {
            $persons[$i] = FieldPersonnel::create([
                'field_team_id' => $team->getKey(),
                'personnel_code' => 'WO_TEST_PERSON_'.$i,
                'name' => 'Test Petugas '.$i,
                'nickname' => 'Test '.$i,
                'crew_number' => intdiv($i - 1, 2) + 1,
                'is_active' => true,
            ]);
        }
        $user = User::factory()->create();
        $user->forceFill(['role' => 'POSKO', 'field_team_id' => $team->getKey(), 'is_active' => true])->save();
        Sanctum::actingAs($user, ['field']);

        $wo = WorkOrder::create([
            'code' => 'WO-CREW-AUDIT-'.$type,
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => $type,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan,
            'posko_id' => $posko->getKey(),
            'field_team_id' => $team->getKey(),
            'work_date' => '2026-11-09',
            'shift_code' => 'A',
        ]);

        return [$wo, $team, $user, $persons];
    }

    private function report(WorkOrder $wo, FieldTeam $team, User $user, array $persons): void
    {
        $report = WorkOrderReport::create([
            'work_order_id' => $wo->getKey(),
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'verification_status' => WorkOrderReport::VERIFICATION_TIDAK_PERLU,
            'occurred_at' => '2026-11-09 02:00:00',
            'received_at' => '2026-11-12 02:00:00',
            'submitted_by_field_team_id' => $team->getKey(),
            'submitted_by_user_id' => $user->getKey(),
        ]);
        foreach ($persons as $person) {
            WorkOrderReportPersonnel::create([
                'work_order_report_id' => $report->getKey(),
                'field_personnel_id' => $person->getKey(),
                'personnel_name' => $person->name,
            ]);
        }
    }

    public function test_detail_shows_schedule_without_creating_report(): void
    {
        [$wo] = $this->createWorkOrder();
        $this->getJson('/api/v1/mobile/work-orders/'.$wo->getKey())
            ->assertOk()
            ->assertJsonPath('data.crew_audit.schedule.status', 'ready')
            ->assertJsonPath('data.crew_audit.schedule.crew_number', 1)
            ->assertJsonPath('data.crew_audit.counts.match', 0)
            ->assertJsonCount(0, 'data.crew_audit.rows');

        $this->assertDatabaseCount('work_order_reports', 0);
    }

    public function test_delayed_offline_report_uses_work_shift_not_receipt_time(): void
    {
        [$wo, $team, $user, $persons] = $this->createWorkOrder();
        $this->report($wo, $team, $user, [$persons[1], $persons[2]]);

        $this->getJson('/api/v1/mobile/work-orders/'.$wo->getKey())
            ->assertOk()
            ->assertJsonPath('data.crew_audit.counts.match', 1)
            ->assertJsonPath('data.crew_audit.rows.0.status', 'match');

        $this->assertDatabaseCount('work_order_reports', 1);
    }

    public function test_other_crew_is_flagged_without_mutating_report(): void
    {
        [$wo, $team, $user, $persons] = $this->createWorkOrder();
        $this->report($wo, $team, $user, [$persons[3]]);

        $this->getJson('/api/v1/mobile/work-orders/'.$wo->getKey())
            ->assertOk()
            ->assertJsonPath('data.crew_audit.counts.different', 1)
            ->assertJsonPath('data.crew_audit.rows.0.status', 'different');

        $this->assertDatabaseCount('work_order_reports', 1);
    }

    public function test_work_order_of_other_posko_remains_inaccessible(): void
    {
        [$wo] = $this->createWorkOrder();
        $anotherPosko = Posko::create([
            'kode_posko' => 'TEST_OTHER',
            'nama_posko' => 'TEST OTHER',
            'is_active' => true,
        ]);
        $wo->update(['posko_id' => $anotherPosko->getKey()]);

        $this->getJson('/api/v1/mobile/work-orders/'.$wo->getKey())->assertNotFound();
    }
}
