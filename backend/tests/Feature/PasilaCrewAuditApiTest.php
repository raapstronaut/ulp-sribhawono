<?php

namespace Tests\Feature;

use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\PasilaNilReport;
use App\Models\PasilaNilReportPersonnel;
use App\Models\PasilaSession;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasilaCrewAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupSession(): array
    {
        config()->set('field_ops.posko_crew_anchor_date', '2026-11-09');

        $posko = Posko::create([
            'kode_posko' => 'JABUNG',
            'nama_posko' => 'JABUNG',
            'is_active' => true,
        ]);
        $team = FieldTeam::create([
            'code' => 'POSKO_JABUNG',
            'name' => 'POSKO JABUNG',
            'role' => 'POSKO',
            'posko_id' => $posko->getKey(),
            'is_active' => true,
        ]);
        $personnel = [];
        for ($i = 1; $i <= 8; $i++) {
            $personnel[$i] = FieldPersonnel::create([
                'field_team_id' => $team->getKey(),
                'personnel_code' => 'TEST_P_'.$i,
                'name' => 'Petugas '.$i,
                'nickname' => 'P'.$i,
                'crew_number' => intdiv($i - 1, 2) + 1,
                'is_active' => true,
            ]);
        }

        $user = User::factory()->create();
        $user->forceFill([
            'role' => 'POSKO',
            'field_team_id' => $team->getKey(),
            'is_active' => true,
        ])->save();
        Sanctum::actingAs($user, ['field']);

        $shift = ShiftInstance::ensure(app(ShiftService::class), '2026-11-09', 'A');
        $session = PasilaSession::create([
            'code' => 'TEST_PASILA_JABUNG_20261109_A',
            'shift_instance_id' => $shift->getKey(),
            'posko_id' => $posko->getKey(),
            'status' => PasilaSession::STATUS_DIBUKA,
            'section_status' => PasilaSession::SECTION_MENUNGGU,
            'opened_at' => $shift->starts_at,
            'opened_trigger' => PasilaSession::TRIGGER_CLI,
        ]);

        return [$session, $team, $user, $personnel];
    }

    private function submitNil(PasilaSession $session, FieldTeam $team, User $user, array $persons): void
    {
        $report = PasilaNilReport::create([
            'pasila_session_id' => $session->getKey(),
            'note' => 'Uji verifikasi identitas petugas',
            'latitude' => -5.2,
            'longitude' => 105.5,
            'occurred_at' => '2026-11-09 02:00:00', // 09:00 WIB
            'received_at' => '2026-11-10 02:00:00', // diterima terlambat
            'status' => PasilaNilReport::STATUS_AKTIF,
            'source' => 'MOBILE',
            'submitted_by_field_team_id' => $team->getKey(),
            'submitted_by_user_id' => $user->getKey(),
        ]);
        foreach ($persons as $person) {
            PasilaNilReportPersonnel::create([
                'pasila_nil_report_id' => $report->getKey(),
                'field_personnel_id' => $person->getKey(),
                'personnel_name' => $person->name,
            ]);
        }
    }

    public function test_detail_api_returns_scheduled_crew_without_changing_session(): void
    {
        [$session] = $this->setupSession();
        $this->getJson('/api/v1/mobile/pasila/'.$session->getKey())
            ->assertOk()
            ->assertJsonPath('data.crew_audit.schedule.status', 'ready')
            ->assertJsonPath('data.crew_audit.schedule.crew_number', 1)
            ->assertJsonPath('data.crew_audit.counts.match', 0)
            ->assertJsonCount(0, 'data.crew_audit.rows');

        $this->assertSame(PasilaSession::STATUS_DIBUKA, $session->fresh()->status);
    }

    public function test_offline_nil_report_is_audited_using_session_shift_not_received_time(): void
    {
        [$session, $team, $user, $persons] = $this->setupSession();
        $this->submitNil($session, $team, $user, [$persons[1], $persons[2]]);

        $this->getJson('/api/v1/mobile/pasila/'.$session->getKey())
            ->assertOk()
            ->assertJsonPath('data.crew_audit.counts.match', 1)
            ->assertJsonPath('data.crew_audit.rows.0.status', 'match');
    }

    public function test_different_crew_member_is_flagged_not_rejected(): void
    {
        [$session, $team, $user, $persons] = $this->setupSession();
        $this->submitNil($session, $team, $user, [$persons[3]]);

        $this->getJson('/api/v1/mobile/pasila/'.$session->getKey())
            ->assertOk()
            ->assertJsonPath('data.crew_audit.counts.different', 1)
            ->assertJsonPath('data.crew_audit.rows.0.status', 'different');

        $this->assertDatabaseCount('pasila_nil_reports', 1);
    }
}
