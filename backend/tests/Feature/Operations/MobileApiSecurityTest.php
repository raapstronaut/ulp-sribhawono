<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Posko;
use App\Models\WorkOrderReport;

class MobileApiSecurityTest extends OperationsTestCase
{
    public function test_login_is_only_for_active_field_accounts_bound_to_active_team(): void
    {
        $this->fieldAccount($this->poskoTeam, 'posko_ok');

        $this->postJson('/api/v1/mobile/auth/login', ['username' => 'posko_ok', 'password' => 'salah', 'device_name' => 'HP'])->assertUnprocessable();
        $this->postJson('/api/v1/mobile/auth/login', ['username' => 'OPS_ADMIN', 'password' => 'password', 'device_name' => 'HP'])->assertUnprocessable();
        $this->postJson('/api/v1/mobile/auth/login', ['username' => 'posko_ok', 'password' => 'rahasia123', 'device_name' => 'HP'])
            ->assertOk()->assertJsonPath('data.account.role', 'POSKO')->assertJsonPath('data.account.team.code', 'POSKO-PUGUNG-A');

        $this->poskoTeam->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/mobile/auth/login', ['username' => 'posko_ok', 'password' => 'rahasia123', 'device_name' => 'HP'])->assertUnprocessable();
    }

    public function test_api_requires_token_and_rejects_deactivated_accounts(): void
    {
        $this->getJson('/api/v1/mobile/work-feed')->assertUnauthorized();

        $account = $this->fieldAccount($this->poskoTeam, 'posko_token');
        $token = $account->createToken('HP', ['field'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.team.code', 'POSKO-PUGUNG-A')
            ->assertJsonPath('data.personnel.0.name', 'Budi Posko')
            ->assertJsonPath('data.reference_codes.RESCHEDULE_REASON.0.code', 'CUACA');

        // Admin menonaktifkan akun: token dicabut dan akses ditolak.
        $this->actingAs($this->admin)->post(route('admin.users.activation', $account), ['active' => 0, 'reason' => 'HP hilang'])->assertSessionHasNoErrors();
        $this->assertSame(0, $account->tokens()->count());
        auth()->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/mobile/work-feed')->assertUnauthorized();
    }

    public function test_team_only_sees_and_acts_on_work_orders_in_its_scope(): void
    {
        $mine = $this->createApkukWorkOrder($this->apkuk());
        $otherPosko = Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        $theirs = $this->createApkukWorkOrder($this->apkuk());
        $theirs->forceFill(['posko_id' => $otherPosko->id])->save();
        $assignedElsewhere = $this->createApkukWorkOrder($this->apkuk());
        $otherTeam = FieldTeam::create(['code' => 'POSKO-PUGUNG-B', 'name' => 'Tim Pugung B', 'role' => 'POSKO', 'posko_id' => $this->posko->id]);
        $assignedElsewhere->forceFill(['field_team_id' => $otherTeam->id])->save();

        $account = $this->fieldAccount($this->poskoTeam, 'posko_scope');
        $this->actingAs($account, 'sanctum');

        $codes = collect($this->getJson('/api/v1/mobile/work-feed')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame([$mine->code], $codes);
        $this->getJson("/api/v1/mobile/work-orders/{$theirs->id}")->assertNotFound();
        $this->postJson("/api/v1/mobile/work-orders/{$assignedElsewhere->id}/start")->assertNotFound();
        $this->getJson('/api/v1/mobile/work-orders/bukan-uuid')->assertNotFound();
        $this->assertSame(WorkOrderStatus::Ditugaskan, $assignedElsewhere->refresh()->status);

        // Konflik workflow: melapor WO yang sudah selesai → 409.
        $mine->forceFill(['status' => WorkOrderStatus::Selesai])->save();
        $this->post("/api/v1/mobile/work-orders/{$mine->id}/reports", [
            'client_request_id' => 'late-1', 'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI, 'installed_quantity' => 1,
            'latitude' => -5.2, 'longitude' => 105.7, 'personnel_ids' => [$this->poskoPerson->id],
            'photos_before' => [$this->photo()], 'photos_after' => [$this->photo()],
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('errors.workflow.0', 'WO berstatus Selesai; tindakan ini tidak dapat dilakukan.');
        $this->postJson("/api/v1/mobile/work-orders/{$mine->id}/start")->assertStatus(409);
    }

    public function test_personnel_from_another_team_cannot_be_claimed(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $account = $this->fieldAccount($this->poskoTeam, 'posko_personnel');
        $outsider = $this->harsusTeam->personnel()->first();
        $this->actingAs($account, 'sanctum');

        $this->post("/api/v1/mobile/work-orders/{$wo->id}/reports", [
            'client_request_id' => 'p-1', 'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI, 'installed_quantity' => 1,
            'latitude' => -5.2, 'longitude' => 105.7, 'personnel_ids' => [$outsider->id],
            'photos_before' => [$this->photo()], 'photos_after' => [$this->photo()],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('personnel_ids');
        $this->assertSame(0, $wo->reports()->count());
    }

    public function test_admin_session_cannot_be_used_on_mobile_api(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/mobile/work-feed')->assertUnauthorized();
    }
}
