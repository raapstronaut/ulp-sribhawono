<?php

namespace Tests\Feature\Operations;

use App\Models\ApkukReconciliationItem;
use App\Models\AuditLog;
use App\Models\WorkOrder;
use App\Services\Apkuk\ApkukReconciliationQueue;
use App\Services\ApkukCandidateService;
use Illuminate\Support\Facades\DB;

/**
 * Integrasi PR #11 (sinkronisasi APKUK tahan perubahan) dengan Web Admin Batch A: laporan yang
 * menunggu rekonsiliasi tidak dihitung aktif di dashboard, halaman APKUK, analitik, master data,
 * peta, maupun kandidat WO; pengarsipan hanya oleh Admin dengan konfirmasi pemilik data dan
 * tercatat di Audit Log umum.
 */
class ApkukPendingIntegrationTest extends OperationsTestCase
{
    public function test_pending_reports_are_not_counted_as_active_anywhere(): void
    {
        $active = $this->row('F.REOG.111111', 'BELUM EKSEKUSI');
        $pending = $this->row('F.REOG.222222', 'KURANG');
        $this->row('F.REOG.333333', 'SELESAI', archived: true);
        $this->queue($pending);

        $this->actingAs($this->admin)->get(route('admin.apkuk.index'))
            ->assertOk()->assertSee('F.REOG.111111')->assertDontSee('F.REOG.222222')->assertDontSee('F.REOG.333333')
            ->assertSee('Menunggu rekonsiliasi');
        $this->actingAs($this->admin)->get(route('admin.apkuk.index', ['tab' => 'menunggu']))
            ->assertOk()->assertSee('F.REOG.222222')->assertDontSee('F.REOG.111111')->assertSee('Menunggu keputusan');
        $this->actingAs($this->admin)->get(route('admin.apkuk.index', ['tab' => 'histori']))
            ->assertOk()->assertSee('F.REOG.333333')->assertDontSee('F.REOG.222222');

        $summary = $this->actingAs($this->admin)->get(route('admin.apkuk.index'))->viewData('summary');
        $this->assertSame([1, 1, 1], [$summary['active'], $summary['pending'], $summary['history']]);

        $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('APKUK menunggu rekonsiliasi');
        $apkuk = $dashboard->viewData('apkuk');
        $this->assertSame([1, 1, 1], [$apkuk['active_reports'], $apkuk['pending_reports'], $apkuk['history_reports']]);

        $analytics = $this->actingAs($this->admin)->get(route('admin.analytics.index'))->assertOk()
            ->assertSee('pending rekonsiliasi dan histori tidak dihitung');
        $statuses = $analytics->viewData('apkukStatus')->pluck('status')->all();
        $this->assertSame(['BELUM EKSEKUSI'], $statuses);

        $penyulang = $this->actingAs($this->admin)->get(route('admin.master-data.penyulang.show', $this->penyulang))->assertOk()
            ->assertSee('pending dan histori tidak dihitung');
        $this->assertSame(1, $penyulang->viewData('sourceCounts')['apkuks']);
        $sources = collect($this->actingAs($this->admin)->get(route('admin.master-data.index', ['tab' => 'sumber']))
            ->assertOk()->viewData('sources'));
        $this->assertSame('1 aktif · 1 menunggu rekonsiliasi · 1 histori', $sources->firstWhere('table', 'apkuks')['extra']);

        $this->actingAs($this->admin)->getJson(route('admin.maps.data'))->assertOk()
            ->assertJsonPath('meta.apkuk_active_total', 1)
            ->assertJsonPath('meta.apkuk_pending_reconciliation', 1);

        // Kandidat WO: pending tidak dapat dipilih; laporan aktif tetap layak.
        $candidates = app(ApkukCandidateService::class)->evaluate();
        $this->assertContains(ApkukCandidateService::ISSUE_MENUNGGU_REKONSILIASI, $candidates[$pending]->issues);
        $this->assertTrue($candidates[$active]->isEligible());
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), [
            'apkuk_id' => $pending, 'posko_id' => $this->posko->id, 'work_date' => '2026-10-28', 'shift_code' => 'A',
        ])->assertSessionHasErrors('apkuk_id');
        $this->assertSame(0, WorkOrder::query()->count());

        $this->actingAs($this->admin)->get(route('admin.apkuk-reconciliation.index'))->assertOk()->assertSee('F.REOG.222222');
    }

    public function test_reconciliation_menu_archives_with_owner_confirmation_and_general_audit_log(): void
    {
        $id = $this->row('F.REOG.444444', 'BELUM EKSEKUSI');
        $wo = $this->createApkukWorkOrder($id);
        $workOrderBefore = $wo->fresh()->toArray();
        $item = $this->queue($id);
        $url = route('admin.apkuk-reconciliation.resolve', $item);
        $payload = [
            'decision' => ApkukReconciliationQueue::DECISION_CONFIRM_REMOVED,
            'reason' => 'Laporan lama tidak berlaku lagi menurut pemilik data.',
            'owner_confirmation' => 'Pemilik SPS BOS (uji) — WA 28/10',
            'acknowledge_active_wo' => '1',
        ];

        // Hanya Admin aktif; akun lapangan Batch A ditolak.
        $field = $this->fieldAccount($this->poskoTeam, 'POSKO_UJI');
        $this->actingAs($field)->get(route('admin.apkuk-reconciliation.index'))->assertForbidden();
        $this->actingAs($field)->post($url, $payload)->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/apkuk-rekonsiliasi/9999999999999999999/selesaikan', $payload)->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.apkuk-reconciliation.index'));

        $this->actingAs($this->admin)->from(route('admin.apkuk-reconciliation.index'))
            ->post($url, ['owner_confirmation' => ''] + $payload)->assertSessionHasErrors('owner_confirmation');
        $this->assertTrue($item->refresh()->isOpen());

        $this->actingAs($this->admin)->from(route('admin.apkuk-reconciliation.index'))->post($url, $payload)->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('apkuks')->where('id', $id)->value('source_missing_at'));
        $this->assertSame($workOrderBefore, $wo->fresh()->toArray(), 'WO lama tidak berubah.');
        $log = AuditLog::query()->where('entity_type', 'apkuk_reconciliation_item')->sole();
        $this->assertSame([AuditLog::ACTION_ARCHIVED, $this->admin->id, (string) $item->id], [$log->action, (int) $log->actor_user_id, $log->entity_id]);
        $this->assertSame('Pemilik SPS BOS (uji) — WA 28/10', $log->after['owner_confirmation']);
        $this->assertSame([$wo->code], $log->after['work_orders']);
        $this->actingAs($this->admin)->get(route('admin.audit-logs.general', ['entity_type' => 'apkuk_reconciliation_item']))
            ->assertOk()->assertSee('Antrean rekonsiliasi APKUK')->assertSee('Diarsipkan');

        $summary = $this->actingAs($this->admin)->get(route('admin.apkuk.index'))->viewData('summary');
        $this->assertSame([0, 0, 1], [$summary['active'], $summary['pending'], $summary['history']]);
    }

    private function queue(int $apkukId): ApkukReconciliationItem
    {
        $key = DB::table('apkuks')->where('id', $apkukId)->value('legacy_key');

        return ApkukReconciliationItem::query()->create([
            'kind' => ApkukReconciliationItem::KIND_MISSING, 'status' => ApkukReconciliationItem::STATUS_OPEN,
            'open_key' => ApkukReconciliationItem::openKey(ApkukReconciliationItem::KIND_MISSING, $key), 'apkuk_id' => $apkukId,
            'legacy_key' => $key, 'source_id' => 'INT-'.$apkukId, 'no_tiang' => DB::table('apkuks')->where('id', $apkukId)->value('no_tiang'),
            'first_detected_at' => now(), 'last_detected_at' => now(),
        ]);
    }

    private function row(string $pole, string $status, bool $archived = false): int
    {
        return DB::table('apkuks')->insertGetId([
            'source_id' => 'INT-'.$pole, 'legacy_key' => hash('sha256', $pole), 'tanggal' => '2026-10-20',
            'penyulang' => 'REOG', 'no_tiang' => $pole, 'kurang_bh' => 2, 'terpasang_bh' => 0, 'status' => $status,
            'sumber_input' => 'APPSHEET_LAMA', 'source_missing_at' => $archived ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
