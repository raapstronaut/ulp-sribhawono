<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\AuditLog;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderStatusLog;

class TeamWorkOrderFlowTest extends OperationsTestCase
{
    public function test_admin_creates_harsus_work_order_on_network_asset_and_duplicates_are_blocked(): void
    {
        $keypoint = $this->keypoint(['nama' => 'FCO REOG 07']);
        $otherFeeder = $this->keypoint(['penyulang' => 'JAIPONG', 'nama' => 'FCO JAIPONG 01']);

        $this->actingAs($this->admin)->get(route('admin.team-work-orders.create', ['team' => 'harsus', 'penyulang_id' => $this->penyulang->id]))
            ->assertOk()->assertSee('FCO REOG 07')->assertDontSee('FCO JAIPONG 01');

        $payload = [
            'work_type' => WorkOrder::TYPE_PEKERJAAN_KHUSUS,
            'penyulang_id' => $this->penyulang->id,
            'target_id' => $keypoint,
            'field_team_id' => $this->harsusTeam->id,
            'work_date' => '2026-10-28',
            'due_date' => '2026-10-30',
            'notes' => 'Ganti fuse link',
        ];

        // Aset di penyulang lain ditolak (lokasi tidak ditebak).
        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'harsus'), ['target_id' => $otherFeeder] + $payload)->assertSessionHasErrors('keypoint_id');
        // Tim pelaksana harus berperan HARSUS.
        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'harsus'), ['field_team_id' => $this->rowTeam->id] + $payload)->assertSessionHasErrors('field_team_id');
        // Target selesai sebelum tanggal kerja ditolak.
        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'harsus'), ['due_date' => '2026-10-27'] + $payload)->assertSessionHasErrors('due_date');

        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'harsus'), $payload)->assertSessionHasNoErrors();
        $wo = WorkOrder::query()->sole();
        $this->assertSame('WO-HARSUS-20261028-0001', $wo->code);
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->status);
        $this->assertSame('2026-10-30 16:59:59', $wo->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(WorkOrderLocationTarget::TYPE_KEYPOINT, $wo->locationTarget->target_type);
        $this->assertSame('FCO REOG 07', $wo->locationTarget->label);

        // WO aktif kedua untuk aset dan jenis sama ditolak.
        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'harsus'), $payload)->assertSessionHasErrors('target');
        $this->assertSame(1, WorkOrder::query()->count());

        // Setelah dibatalkan, kunci aset dilepas dan WO baru boleh dibuat.
        $this->actingAs($this->admin)->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Diganti jadwal baru'])->assertSessionHasNoErrors();
        $this->assertNull($wo->locationTarget->fresh()->active_target_key);
        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'harsus'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_row_work_order_on_vegetation_point_completes_through_verification(): void
    {
        $vegetation = $this->vegetation();
        $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'row'), [
            'work_type' => WorkOrder::TYPE_PANGKAS,
            'penyulang_id' => $this->penyulang->id,
            'target_id' => $vegetation,
            'work_date' => '2026-10-28',
            'due_date' => '2026-10-28',
        ])->assertSessionHasNoErrors();
        $wo = WorkOrder::query()->sole();
        $this->assertSame(WorkOrder::TEAM_ROW, $wo->team_type);
        $this->assertSame('-5.2222000', (string) $wo->locationTarget->latitude, 'Koma desimal sumber diurai tanpa menebak.');

        // Tim ROW (API) melapor selesai → menunggu verifikasi Admin → ACC → Selesai.
        $account = $this->fieldAccount($this->rowTeam, 'row_team');
        $this->actingAs($account, 'sanctum');
        $this->getJson('/api/v1/mobile/work-feed')->assertOk()->assertJsonPath('data.0.code', $wo->code);
        $person = $this->rowTeam->personnel()->first();
        $this->post("/api/v1/mobile/work-orders/{$wo->id}/reports", [
            'client_request_id' => 'row-1',
            'report_type' => WorkOrderReport::TYPE_PEKERJAAN_SELESAI,
            'latitude' => -5.2222, 'longitude' => 105.7777,
            'personnel_ids' => [$person->id],
            'photos_before' => [$this->photo()], 'photos_after' => [$this->photo()],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.work_order.status', 'MENUNGGU_VERIFIKASI');
        auth()->forgetGuards();

        $report = $wo->reports()->sole();
        $this->actingAs($this->admin)->get(route('admin.reviews.index', ['category' => 'reports']))->assertOk()->assertSee($wo->code)->assertSee('Pekerjaan selesai');
        $this->actingAs($this->admin)->post(route('admin.reviews.reports.approve', $report))->assertSessionHasNoErrors();
        $this->assertSame(WorkOrderStatus::Selesai, $wo->refresh()->status);
        $this->assertNull($wo->locationTarget->active_target_key);
    }

    public function test_escalation_approval_creates_child_work_order_atomically_and_rejection_creates_none(): void
    {
        $source = $this->createApkukWorkOrder($this->apkuk(['no_tiang' => 'F.REOG.000900']));
        $account = $this->fieldAccount($this->poskoTeam, 'posko_eskalasi');
        $this->actingAs($account, 'sanctum');

        // Alasan harus dari kode referensi dan tim tujuan harus tim lain.
        $this->postJson("/api/v1/mobile/work-orders/{$source->id}/escalations", ['client_request_id' => 'e0', 'target_team_type' => 'POSKO', 'target_work_type' => 'PEMASANGAN_APKUK', 'reason_code' => 'BUTUH_PADAM', 'note' => 'Perlu padam'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_team_type');
        $this->postJson("/api/v1/mobile/work-orders/{$source->id}/escalations", ['client_request_id' => 'e1', 'target_team_type' => 'HARSUS', 'target_work_type' => 'PEKERJAAN_KHUSUS', 'reason_code' => 'BUTUH_PADAM', 'note' => 'Tiang miring perlu pemadaman'])
            ->assertCreated();
        // Permintaan kedua saat masih menunggu ACC ditolak.
        $this->postJson("/api/v1/mobile/work-orders/{$source->id}/escalations", ['client_request_id' => 'e2', 'target_team_type' => 'HARSUS', 'target_work_type' => 'PEKERJAAN_KHUSUS', 'reason_code' => 'BUTUH_PADAM', 'note' => 'Ulang'])
            ->assertUnprocessable();
        auth()->forgetGuards();

        $escalation = WorkEscalation::query()->sole();
        $this->assertSame(WorkOrderStatus::Ditugaskan, $source->refresh()->status, 'Pengajuan limpahan tidak mengubah status sumber.');

        $this->actingAs($this->admin)->post(route('admin.reviews.escalations.approve', $escalation), [
            'field_team_id' => $this->harsusTeam->id, 'work_date' => '2026-10-28', 'due_date' => '2026-10-29', 'review_note' => 'Disetujui, jadwalkan pemadaman',
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $child = WorkOrder::query()->where('parent_work_order_id', $source->id)->sole();
        $this->assertSame(WorkOrderStatus::SelesaiDiteruskan, $source->status);
        $this->assertNull($source->apkukTarget->active_target_key);
        $this->assertSame(WorkOrder::SOURCE_ESCALATION, $child->source);
        $this->assertSame(WorkOrder::TEAM_HARSUS, $child->team_type);
        $this->assertSame($this->harsusTeam->id, $child->field_team_id);
        $this->assertSame('F.REOG.000900', $child->locationTarget->label);
        $this->assertSame(WorkOrderLocationTarget::TYPE_WORK_ORDER, $child->locationTarget->target_type);
        $this->assertSame(WorkEscalation::STATUS_DISETUJUI, $escalation->refresh()->status);
        $this->assertSame($child->id, $escalation->child_work_order_id);
        $this->assertSame(1, WorkOrderStatusLog::query()->where('work_order_id', $source->id)->where('action', WorkOrderStatusLog::ACTION_ESCALATION_APPROVED)->count());
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'work_escalation')->where('action', AuditLog::ACTION_APPROVED)->count());
        $this->assertFalse($child->supportsAdminCancellation(), 'Child limpahan tidak dapat dibatalkan sampai aturannya disahkan.');

        // Limpahan kedua dari WO lain ditolak: tidak ada child WO.
        $other = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->admin)->post(route('admin.work-orders.escalations', $other), ['target' => 'ROW|PANGKAS', 'reason_code' => 'BUTUH_PADAM', 'note' => 'Ada pohon menyentuh kabel'])->assertSessionHasNoErrors();
        $second = WorkEscalation::query()->where('source_work_order_id', $other->id)->sole();
        $this->actingAs($this->admin)->post(route('admin.reviews.escalations.reject', $second), ['review_note' => 'Cukup dirabas POSKO'])->assertSessionHasNoErrors();
        $this->assertSame(WorkEscalation::STATUS_DITOLAK, $second->refresh()->status);
        $this->assertNull($second->child_work_order_id);
        $this->assertSame(WorkOrderStatus::Ditugaskan, $other->refresh()->status);
        $this->assertSame(2, WorkOrder::query()->whereNull('parent_work_order_id')->where('team_type', 'POSKO')->count());
    }

    public function test_issue_is_reported_tracked_in_special_case_and_resolved_with_audit(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $this->actingAs($this->admin)->post(route('admin.work-orders.issues', $wo), ['issue_type' => 'LOKASI_TIDAK_SESUAI', 'description' => 'Nomor tiang di lapangan berbeda'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.special-case.index'))
            ->assertOk()->assertSee('Lokasi tidak sesuai')->assertSee('Nomor tiang di lapangan berbeda');
        $this->actingAs($this->admin)->getJson(route('admin.dashboard'))->assertJsonPath('pending.issues', 1);

        $issue = $wo->issues()->sole();
        $this->actingAs($this->admin)->post(route('admin.special-case.issues.resolve', $issue), ['resolution_action' => 'DATA_DIKOREKSI', 'resolution_note' => 'Nomor tiang dikoreksi di SPS BOS'])->assertSessionHasNoErrors();
        $this->assertSame('SELESAI', $issue->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'work_order_issue')->count());
        $this->actingAs($this->admin)->post(route('admin.special-case.issues.resolve', $issue), ['resolution_action' => 'LANJUT', 'resolution_note' => 'Dua kali'])->assertSessionHasErrors('workflow');
    }
}
