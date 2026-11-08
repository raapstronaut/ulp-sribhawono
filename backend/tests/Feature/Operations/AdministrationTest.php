<?php

namespace Tests\Feature\Operations;

use App\Models\AuditLog;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\FieldTeamShiftAssignment;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReportPersonnel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdministrationTest extends OperationsTestCase
{
    public function test_master_data_crud_with_validation_reference_protection_and_audit(): void
    {
        // Tim POSKO wajib Posko; kode unik.
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'tim'), ['code' => 'POSKO-X', 'name' => 'Tim X', 'role' => 'POSKO', 'is_active' => 1])->assertSessionHasErrors('posko_id');
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'tim'), ['code' => 'ROW-1', 'name' => 'Duplikat', 'role' => 'ROW', 'is_active' => 1])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'tim'), ['code' => 'harsus-2', 'name' => 'Tim HARSUS 2', 'role' => 'HARSUS', 'is_active' => 1])->assertSessionHasNoErrors();
        $team = FieldTeam::query()->where('code', 'HARSUS-2')->sole();

        // Petugas + kode referensi + jadwal shift.
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'petugas'), ['field_team_id' => $team->id, 'name' => 'Andi', 'personnel_code' => 'p-9', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'kode'), ['category' => 'RESCHEDULE_REASON', 'code' => 'akses_tertutup', 'label' => 'Akses jalan tertutup', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'kode'), ['category' => 'RESCHEDULE_REASON', 'code' => 'CUACA', 'label' => 'Duplikat', 'is_active' => 1])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'jadwal'), ['field_team_id' => $this->poskoTeam->id, 'shift_code' => 'A', 'effective_from' => '2026-11-01', 'effective_until' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'jadwal'), ['field_team_id' => $this->poskoTeam->id, 'shift_code' => 'B', 'effective_from' => '2026-11-15'])->assertSessionHasErrors('effective_from');
        $this->actingAs($this->admin)->post(route('admin.master-data.manage.store', 'jadwal'), ['field_team_id' => $this->rowTeam->id, 'shift_code' => 'A', 'effective_from' => '2026-11-01'])->assertSessionHasErrors('field_team_id');

        $this->assertSame('AKSES_TERTUTUP', ReferenceCode::query()->where('label', 'Akses jalan tertutup')->value('code'));
        $this->assertSame(1, FieldTeamShiftAssignment::query()->count());

        // Ubah tim (nonaktifkan) tercatat sebagai DEACTIVATED dengan nilai sebelum/sesudah.
        $this->actingAs($this->admin)->put(route('admin.master-data.manage.update', ['tim', $team->id]), ['code' => 'HARSUS-2', 'name' => 'Tim HARSUS 2', 'role' => 'HARSUS', 'is_active' => 0, 'reason' => 'Tim dibubarkan'])->assertSessionHasNoErrors();
        $deactivated = AuditLog::query()->where('entity_type', 'field_team')->where('action', AuditLog::ACTION_DEACTIVATED)->sole();
        $this->assertSame(['is_active' => true], $deactivated->before);
        $this->assertSame('Tim dibubarkan', $deactivated->reason);

        // Data yang direferensikan tidak boleh dihapus.
        $person = FieldPersonnel::query()->where('name', 'Andi')->sole();
        $report = WorkOrderReport::create(['work_order_id' => $this->createApkukWorkOrder($this->apkuk())->id, 'report_type' => 'PEMASANGAN_SELESAI', 'verification_status' => 'TIDAK_PERLU']);
        WorkOrderReportPersonnel::create(['work_order_report_id' => $report->id, 'field_personnel_id' => $person->id, 'personnel_name' => 'Andi']);
        $this->actingAs($this->admin)->delete(route('admin.master-data.manage.destroy', ['petugas', $person->id]), ['reason' => 'Salah input'])->assertSessionHasErrors('delete');
        $this->actingAs($this->admin)->delete(route('admin.master-data.manage.destroy', ['tim', $team->id]), ['reason' => 'Salah input'])->assertSessionHasErrors('delete');
        $this->assertNotNull(FieldPersonnel::query()->find($person->id));

        // Kode yang belum dipakai boleh dihapus dengan alasan, tercatat di audit.
        $code = ReferenceCode::query()->where('code', 'AKSES_TERTUTUP')->sole();
        $this->actingAs($this->admin)->delete(route('admin.master-data.manage.destroy', ['kode', $code->id]), ['reason' => 'Tidak dipakai'])->assertSessionHasNoErrors();
        $this->assertNull(ReferenceCode::query()->find($code->id));
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'reference_code')->where('action', AuditLog::ACTION_DELETED)->count());

        $this->actingAs($this->admin)->get(route('admin.audit-logs.general', ['entity_type' => 'field_team']))->assertOk()->assertSee('Tim HARSUS 2')->assertSee('Dinonaktifkan');
    }

    public function test_user_management_enforces_access_rules_and_never_logs_passwords(): void
    {
        // Akun lapangan wajib tim dengan role sama.
        $base = ['name' => 'Akun Posko', 'username' => 'posko_baru', 'email' => 'posko@ulp.test', 'password' => 'Sandi12345', 'password_confirmation' => 'Sandi12345', 'is_active' => 1];
        $this->actingAs($this->admin)->post(route('admin.users.store'), $base + ['role' => 'POSKO'])->assertSessionHasErrors('field_team_id');
        $this->actingAs($this->admin)->post(route('admin.users.store'), $base + ['role' => 'POSKO', 'field_team_id' => $this->rowTeam->id])->assertSessionHasErrors('field_team_id');
        $this->actingAs($this->admin)->post(route('admin.users.store'), $base + ['role' => 'POSKO', 'field_team_id' => $this->poskoTeam->id])->assertSessionHasNoErrors();
        $field = User::query()->where('username', 'posko_baru')->sole();
        $this->assertTrue($field->canUseFieldApi());
        $this->assertFalse($field->canAccessAdmin());

        // Akun lapangan tidak dapat membuka Web Admin.
        $this->actingAs($field)->get(route('admin.dashboard'))->assertForbidden();
        auth()->forgetGuards();

        // Admin tidak dapat menonaktifkan / menurunkan diri sendiri; minimal satu ADMIN aktif.
        $this->actingAs($this->admin)->post(route('admin.users.activation', $this->admin), ['active' => 0, 'reason' => 'Coba nonaktif'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->put(route('admin.users.update', $this->admin), ['name' => 'Admin', 'username' => 'OPS_ADMIN', 'email' => $this->admin->email, 'role' => 'POSKO', 'field_team_id' => $this->poskoTeam->id])->assertSessionHasErrors('role');
        $second = User::factory()->admin()->create(['username' => 'ADMIN_DUA']);
        $this->actingAs($this->admin)->post(route('admin.users.activation', $second), ['active' => 0, 'reason' => 'Pindah tugas'])->assertSessionHasNoErrors();
        $this->assertFalse($second->refresh()->is_active);

        // Reset kata sandi mencabut token; audit tidak berisi kata sandi.
        $field->createToken('HP');
        $this->actingAs($this->admin)->post(route('admin.users.password', $field), ['password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9', 'reason' => 'Lupa sandi'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('BaruSekali9', $field->refresh()->password));
        $this->assertSame(0, $field->tokens()->count());

        $auditJson = AuditLog::query()->where('entity_type', 'user')->get()->map(fn ($log) => json_encode([$log->before, $log->after]))->implode(' ');
        $this->assertStringNotContainsString('Sandi12345', $auditJson);
        $this->assertStringNotContainsString('BaruSekali9', $auditJson);
        $this->assertStringNotContainsString('password', $auditJson);
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'user')->where('action', AuditLog::ACTION_PASSWORD_RESET)->count());

        $this->actingAs($this->admin)->get(route('admin.users.show', $field))->assertOk()->assertSee('Kata sandi direset')->assertDontSee($field->password);
    }

    public function test_analytics_export_contains_real_rows_and_neutralises_formulas(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk(['no_tiang' => '=HYPERLINK("x")']));
        $response = $this->actingAs($this->admin)->get(route('admin.analytics.export', ['period' => 30]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString($wo->code, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('kode,tim,jenis_pekerjaan', $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'Header + satu baris WO.');
    }

    public function test_validation_messages_are_indonesian_when_locale_is_id(): void
    {
        app()->setLocale('id');

        $this->actingAs($this->admin)
            ->post(route('admin.team-work-orders.store', 'row'), ['work_type' => 'RABAS', 'work_date' => '2026-10-28', 'due_date' => '2026-10-27'])
            ->assertSessionHasErrors([
                'penyulang_id' => 'Penyulang wajib diisi.',
                'due_date' => 'Target selesai tidak boleh sebelum tanggal kerja.',
            ]);
    }

    public function test_new_operational_migration_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_10_28_000001_create_admin_operations_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('work_escalations'));
        $this->assertFalse(Schema::hasColumn('users', 'field_team_id'));
        $this->assertTrue(Schema::hasTable('work_orders'), 'Rollback hanya menghapus tabel baru.');

        $migration->up();
        $this->assertTrue(Schema::hasTable('work_escalations'));
        $this->assertTrue(Schema::hasColumn('users', 'field_team_id'));
    }
}
