<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ApkukEndToEndFlowTest extends OperationsTestCase
{
    public function test_apkuk_from_source_to_completion_via_mobile_api_updates_dashboard_analytics_and_audit(): void
    {
        // 1–2. Laporan sumber tersinkron terlihat di Web Admin sebagai kebutuhan; histori tidak.
        $active = $this->apkuk(['no_tiang' => 'F.REOG.000501', 'kurang_bh' => 4]);
        $this->apkuk(['no_tiang' => 'F.REOG.000502', 'source_missing_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.apkuk.index'))
            ->assertOk()
            ->assertSee('F.REOG.000501')
            ->assertDontSee('F.REOG.000502')
            ->assertSee(route('admin.work-orders.create', ['apkuk_id' => $active]), false);
        $this->actingAs($this->admin)->get(route('admin.apkuk.index', ['tab' => 'histori']))
            ->assertOk()->assertSee('F.REOG.000502');

        // 3–4. Admin membuat WO dan menunjuk tim.
        $wo = $this->createApkukWorkOrder($active);
        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $wo), [
            'work_date' => '2026-10-28', 'shift_code' => 'A', 'field_team_id' => $this->poskoTeam->id, 'reason' => 'Tunjuk tim shift A',
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->poskoTeam->id, $wo->refresh()->field_team_id);

        // 5–6. Tim lapangan (API) melihat WO, mulai, lalu melapor dengan foto dan GPS.
        $account = $this->fieldAccount($this->poskoTeam, 'posko_pugung_a');
        $token = $this->postJson('/api/v1/mobile/auth/login', ['username' => 'posko_pugung_a', 'password' => 'rahasia123', 'device_name' => 'HP Uji'])
            ->assertOk()->json('data.token');
        $api = fn () => $this->withHeader('Authorization', 'Bearer '.$token);

        $api()->getJson('/api/v1/mobile/work-feed')->assertOk()->assertJsonPath('data.0.code', $wo->code)
            ->assertJsonPath('data.0.target.google_maps_url', 'https://www.google.com/maps/search/?api=1&query=-5.2,105.7');
        $api()->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertOk()->assertJsonPath('data.status', 'DIKERJAKAN');

        $payload = [
            'client_request_id' => 'req-apkuk-1',
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'installed_quantity' => 3,
            'latitude' => -5.2001,
            'longitude' => 105.7001,
            'personnel_ids' => [$this->poskoPerson->id],
            'photos_before' => [$this->photo('before.jpg')],
            'photos_after' => [$this->photo('after.jpg')],
        ];
        $api()->post("/api/v1/mobile/work-orders/{$wo->id}/reports", $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.verification_status', 'TIDAK_PERLU')
            ->assertJsonPath('data.work_order.status', 'SELESAI');

        // Pengiriman ulang (offline retry) dengan client_request_id sama tidak membuat laporan ganda.
        $api()->post("/api/v1/mobile/work-orders/{$wo->id}/reports", ['photos_before' => [$this->photo()], 'photos_after' => [$this->photo()]] + $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, WorkOrderReport::query()->where('work_order_id', $wo->id)->count());

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Selesai, $wo->status);
        $this->assertNotNull($wo->completed_at);
        $this->assertSame(3, $wo->apkukTarget->installed_quantity);
        $this->assertSame(1, $wo->apkukTarget->remaining_quantity);
        $this->assertNull($wo->apkukTarget->active_target_key, 'Kunci tiang dilepas setelah selesai.');
        $this->assertCount(2, $wo->media);
        foreach ($wo->media as $media) {
            Storage::disk('local')->assertExists($media->storage_path);
        }

        // Sumber apkuks tetap tidak diubah (hanya dirujuk).
        $this->assertSame(0, (int) DB::table('apkuks')->where('id', $active)->value('terpasang_bh'));

        // 8. Dashboard, analitik, dan audit konsisten.
        $this->actingAs($this->admin)->getJson(route('admin.dashboard'))
            ->assertJsonPath('kpis.selesai_periode', 1)
            ->assertJsonPath('kpis.active', 0)
            ->assertJsonPath('poskoStatuses.0.apkuk_completed', 1)
            ->assertJsonPath('apkuk.installed_period', 3);
        $this->actingAs($this->admin)->get(route('admin.analytics.index', ['period' => 30]))
            ->assertOk()->assertSee('Pemasangan APKUK');
        $actions = WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame([
            WorkOrderStatusLog::ACTION_CREATED,
            WorkOrderStatusLog::ACTION_UPDATED,
            WorkOrderStatusLog::ACTION_STARTED,
            WorkOrderStatusLog::ACTION_REPORT_SUBMITTED,
        ], $actions);
        $this->assertSame('MOBILE', WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->where('action', WorkOrderStatusLog::ACTION_REPORT_SUBMITTED)->value('payload')['channel']);

        $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('Pemasangan selesai')
            ->assertSee('Budi Posko')
            ->assertSee(route('admin.media.work-orders', $wo->media->first()), false);
        $this->actingAs($this->admin)->get(route('admin.media.work-orders', $wo->media->first()))->assertOk();
    }

    public function test_finding_already_complete_requires_admin_verification_and_can_be_returned(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());

        $submit = fn () => $this->actingAs($this->admin)->post(route('admin.work-orders.reports', $wo), [
            'report_type' => WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP,
            'occurred_at' => '2026-10-28T08:30',
            'personnel_names' => 'Petugas Tanpa Master',
            'photos_after' => [$this->photo()],
        ]);
        $submit()->assertSessionHasNoErrors();
        $wo->refresh();
        $this->assertSame(WorkOrderStatus::MenungguVerifikasi, $wo->status);
        $report = $wo->reports()->first();
        $this->assertSame(WorkOrderReport::VERIFICATION_MENUNGGU, $report->verification_status);

        // Tolak wajib alasan; WO kembali ke Ditugaskan agar tim melapor ulang.
        $this->actingAs($this->admin)->post(route('admin.reviews.reports.reject', $report), ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->actingAs($this->admin)->post(route('admin.reviews.reports.reject', $report), ['review_note' => 'Foto tidak menunjukkan tiang'])->assertSessionHasNoErrors();
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->refresh()->status);
        $this->assertSame(WorkOrderReport::VERIFICATION_DITOLAK, $report->refresh()->verification_status);
        $this->assertSame($this->admin->id, $report->verified_by_user_id);

        // Laporan ulang lalu ACC → Selesai, realisasi 0 dan sisa 0.
        $submit()->assertSessionHasNoErrors();
        $second = $wo->reports()->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)->sole();
        $this->actingAs($this->admin)->post(route('admin.reviews.reports.approve', $second), ['review_note' => 'Sesuai foto'])->assertSessionHasNoErrors();
        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Selesai, $wo->status);
        $this->assertSame(0, $wo->apkukTarget->installed_quantity);
        $this->assertSame(0, $wo->apkukTarget->remaining_quantity);

        // Keputusan kedua atas laporan yang sama ditolak (409-like conflict di web).
        $this->actingAs($this->admin)->from(route('admin.reviews.index'))->post(route('admin.reviews.reports.approve', $second))
            ->assertRedirect(route('admin.reviews.index'))->assertSessionHasErrors('workflow');
        $this->assertSame(
            [WorkOrderStatusLog::ACTION_REPORT_REJECTED, WorkOrderStatusLog::ACTION_REPORT_APPROVED],
            WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->whereIn('action', [WorkOrderStatusLog::ACTION_REPORT_REJECTED, WorkOrderStatusLog::ACTION_REPORT_APPROVED])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_completion_requires_before_after_photos_personnel_and_quantity(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $base = ['report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI, 'occurred_at' => '2026-10-28T08:30', 'installed_quantity' => 2, 'personnel_ids' => [$this->poskoPerson->id]];

        $this->actingAs($this->admin)->post(route('admin.work-orders.reports', $wo), $base + ['photos_after' => [$this->photo()]])->assertSessionHasErrors('photos');
        $this->actingAs($this->admin)->post(route('admin.work-orders.reports', $wo), array_merge($base, ['personnel_ids' => []]) + ['photos_before' => [$this->photo()], 'photos_after' => [$this->photo()]])->assertSessionHasErrors('personnel_ids');
        $this->actingAs($this->admin)->post(route('admin.work-orders.reports', $wo), array_merge($base, ['installed_quantity' => null]) + ['photos_before' => [$this->photo()], 'photos_after' => [$this->photo()]])->assertSessionHasErrors('installed_quantity');
        $this->actingAs($this->admin)->post(route('admin.work-orders.reports', $wo), array_merge($base, ['occurred_at' => '2026-10-29T08:30']) + ['photos_before' => [$this->photo()], 'photos_after' => [$this->photo()]])->assertSessionHasErrors('occurred_at');

        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->refresh()->status);
        $this->assertSame(0, $wo->reports()->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Foto dari percobaan gagal tidak tertinggal.');
    }

    public function test_reschedule_request_then_approval_moves_schedule_and_rejection_restores_status(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());
        $account = $this->fieldAccount($this->poskoTeam, 'posko_reschedule');
        $this->actingAs($account, 'sanctum');

        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/reschedules", [
            'client_request_id' => 'rs-1', 'reason_code' => 'TIDAK_ADA', 'note' => 'Hujan deras', 'proposed_work_date' => '2026-10-29', 'proposed_shift_code' => 'A',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/reschedules", [
            'client_request_id' => 'rs-1', 'reason_code' => 'CUACA', 'note' => 'Hujan deras sejak pagi', 'proposed_work_date' => '2026-10-29', 'proposed_shift_code' => 'A',
        ])->assertCreated();
        $this->assertSame(WorkOrderStatus::RescheduleDiajukan, $wo->refresh()->status);

        $reschedule = $wo->reschedules()->sole();
        auth()->forgetGuards();
        $this->actingAs($this->admin)->post(route('admin.reviews.reschedules.approve', $reschedule))->assertSessionHasNoErrors();
        $wo->refresh();
        $this->assertSame(WorkOrderStatus::DijadwalkanUlang, $wo->status);
        $this->assertSame('2026-10-29', $wo->work_date->toDateString());
        $this->assertSame('2026-10-29 01:00:00', $wo->scheduled_start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-29 09:00:00', $wo->due_at->utc()->format('Y-m-d H:i:s'));

        // Pengajuan kedua ditolak → kembali ke status kerja semula (dijadwalkan ulang belum dimulai → Ditugaskan).
        $this->actingAs($account, 'sanctum');
        $this->postJson("/api/v1/mobile/work-orders/{$wo->id}/reschedules", [
            'client_request_id' => 'rs-2', 'reason_code' => 'CUACA', 'note' => 'Masih hujan', 'proposed_work_date' => '2026-10-30', 'proposed_shift_code' => 'B',
        ])->assertCreated();
        auth()->forgetGuards();
        $second = $wo->reschedules()->where('status', 'MENUNGGU_ACC')->sole();
        $this->actingAs($this->admin)->post(route('admin.reviews.reschedules.reject', $second), ['review_note' => 'Tetap dikerjakan sesuai jadwal'])->assertSessionHasNoErrors();
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->refresh()->status);
        $this->assertSame('2026-10-29', $wo->work_date->toDateString());
    }

    public function test_admin_schedule_edit_requires_reason_and_records_before_after(): void
    {
        $wo = $this->createApkukWorkOrder($this->apkuk());

        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $wo), ['work_date' => '2026-10-28', 'shift_code' => 'B'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $wo), ['work_date' => '2026-10-27', 'shift_code' => 'A', 'reason' => 'Mundur ke kemarin'])->assertSessionHasErrors('shift_code');
        $this->actingAs($this->admin)->put(route('admin.work-orders.update', $wo), ['work_date' => '2026-10-28', 'shift_code' => 'B', 'reason' => 'Pindah ke shift sore'])->assertSessionHasNoErrors();

        $wo->refresh();
        $this->assertSame('B', $wo->shift_code);
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->status, 'Ubah jadwal Admin tidak mengubah status.');
        $log = WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->where('action', WorkOrderStatusLog::ACTION_UPDATED)->sole();
        $this->assertSame('A', $log->payload['before']['shift_code']);
        $this->assertSame('B', $log->payload['after']['shift_code']);
        $this->assertSame('Pindah ke shift sore', $log->reason);

        // WO selesai tidak dapat diedit.
        $wo->forceFill(['status' => WorkOrderStatus::Selesai])->save();
        $this->actingAs($this->admin)->get(route('admin.work-orders.edit', $wo))->assertStatus(409);
        $this->actingAs($this->admin)->from(route('admin.work-orders.show', $wo))
            ->put(route('admin.work-orders.update', $wo), ['work_date' => '2026-10-28', 'shift_code' => 'A', 'reason' => 'Coba ubah'])
            ->assertSessionHasErrors('workflow');
    }

    public function test_duplicate_active_work_order_for_same_pole_is_rejected(): void
    {
        $id = $this->apkuk();
        $this->createApkukWorkOrder($id);

        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), [
            'apkuk_id' => $id, 'posko_id' => $this->posko->id, 'work_date' => '2026-10-28', 'shift_code' => 'B',
        ])->assertSessionHasErrors('apkuk_id');
        $this->assertSame(1, WorkOrder::query()->count());
    }
}
