<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\P0Item;
use App\Models\PasilaFinding;
use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderStatusLog;
use App\Services\Apkuk\Generation\ApkukShiftQuota;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Pasila\P0Service;
use App\Services\Pasila\PasilaFindingService;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use App\Services\Work\WorkOrderEditor;
use App\Services\WorkOrderLifecycleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * B3.3 — P0 POSKO: usulan SYSTEM (ACC/tolak), P0 manual dan pengganti, pelaksanaan oleh POSKO,
 * target P0 shift dihitung dari penyelesaian. Data simulasi.
 *
 * Waktu uji: 29 Okt 2026 09:00 WIB (Shift A hari operasional 29). PUGUNG memegang LBSM BEDANA
 * (BEDANA) dan LBSM GUNUNG MAS (REOG); WAY JEPARA memegang REC BRAJA DEWA (REOG).
 */
class P0WorkflowTest extends OperationsTestCase
{
    private Posko $wayJepara;

    private FieldTeam $wayJeparaTeam;

    private User $pugungAccount;

    private PasilaSession $session;

    private PasilaSession $wayJeparaSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 29, 9, 0, 0, ShiftService::TIMEZONE));
        config(['pasila.opener.enabled' => true]);
        $this->wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);
        $this->wayJeparaTeam = FieldTeam::create(['code' => 'POSKO-WJ', 'name' => 'Tim Way Jepara', 'role' => 'POSKO', 'posko_id' => $this->wayJepara->id]);
        $this->wayJeparaTeam->personnel()->create(['name' => 'Wati Jepara']);
        foreach ([['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], [null, 'REC BRAJA DEWA', 'WAY JEPARA'], ['BEDANA', 'LBSM BEDANA', 'PUGUNG']] as $i => [$p, $s, $k]) {
            DB::table('section_pic_source_rows')->insert(['penyulang_raw' => $p, 'pemutus_raw' => $s, 'posko_pic_raw' => $k, 'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM']);

        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
        $this->session = PasilaSession::query()->where('posko_id', $this->posko->id)->with('shiftInstance')->sole();
        $this->wayJeparaSession = PasilaSession::query()->where('posko_id', $this->wayJepara->id)->sole();
        $this->pugungAccount = $this->fieldAccount($this->poskoTeam, 'posko_pugung_app');
    }

    public function test_system_proposal_is_not_work_until_admin_approves_it(): void
    {
        $finding = $this->finding($this->session, 'LBSM BEDANA');
        $item = $this->propose($finding);

        $this->assertSame([P0Item::APPROVAL_MENUNGGU, P0Item::STAGE_MENUNGGU_ACC, null], [$item->approval_status, $item->stage(), $item->work_order_id]);
        $this->assertSame(['UJI_SIMULASI', false], [$item->recommendation['strategy'], $item->recommendation['is_machine_learning']]);
        $this->assertSame(['PUGUNG', 'BEDANA', 'LBSM BEDANA', 'P0-20261029-A-PUGUNG-01'], [$item->posko->nama_posko, $item->penyulang->kode_penyulang, $item->section_name, $item->code]);
        $this->assertSame(0, WorkOrder::query()->count(), 'Usulan belum menjadi pekerjaan.');
        $this->api($this->pugungAccount)->getJson('/api/v1/mobile/p0')->assertOk()->assertJsonPath('data', []);
        $this->api($this->pugungAccount)->getJson('/api/v1/mobile/work-feed')->assertOk()->assertJsonPath('data', []);
        $this->flushHeaders();

        $this->actingAs($this->admin)->post(route('admin.p0.approve', $item), ['work_date' => '2026-10-29', 'shift_code' => 'A', 'review_note' => 'Prioritas'])
            ->assertRedirect(route('admin.p0.show', $item))->assertSessionHas('flash');

        $item->refresh();
        $wo = $item->workOrder;
        $this->assertSame([P0Item::APPROVAL_DISETUJUI, $this->admin->id], [$item->approval_status, $item->reviewed_by_user_id]);
        $this->assertSame([WorkOrder::TEAM_POSKO, WorkOrder::TYPE_P0, WorkOrder::SOURCE_SYSTEM, WorkOrderStatus::Ditugaskan, $this->posko->id, 'LBSM BEDANA', 'A'], [$wo->team_type, $wo->work_type, $wo->source, $wo->status, $wo->posko_id, $wo->section_name, $wo->shift_code]);
        $this->assertStringStartsWith('WO-P0-20261029-', $wo->code);
        $target = $wo->locationTarget;
        $this->assertSame([WorkOrderLocationTarget::TYPE_PASILA, 'PASILA:'.$finding->id, 'POSKO|P0|PASILA:'.$finding->id], [$target->target_type, $target->target_key, $target->active_target_key]);
        $log = WorkOrderStatusLog::query()->where('work_order_id', $wo->id)->where('action', WorkOrderStatusLog::ACTION_CREATED)->sole();
        $this->assertSame([$item->id, $this->session->id], [$log->payload['p0_item_id'], $log->payload['pasila_session_id']]);
        $audit = AuditLog::query()->where('entity_type', 'p0_item')->where('action', AuditLog::ACTION_APPROVED)->sole();
        $this->assertSame([P0Item::APPROVAL_MENUNGGU, P0Item::APPROVAL_DISETUJUI], [$audit->before['approval_status'], $audit->after['approval_status']]);

        // ACC ulang (klik ganda/permintaan berulang) ditolak tanpa WO kedua.
        $this->actingAs($this->admin)->post(route('admin.p0.approve', $item), ['work_date' => '2026-10-29', 'shift_code' => 'A'])->assertSessionHasErrors('workflow');
        $this->actingAs($this->admin)->post(route('admin.p0.reject', $item), ['review_note' => 'Tolak setelah ACC'])->assertSessionHasErrors('workflow');
        $this->assertSame(1, WorkOrder::query()->count());

        $this->api($this->pugungAccount)->getJson('/api/v1/mobile/p0')->assertOk()
            ->assertJsonPath('data.0.code', $item->code)->assertJsonPath('data.0.pasila_session.code', 'PSL-20261029-A-PUGUNG')->assertJsonPath('data.0.work_order.code', $wo->code);
        $this->api($this->pugungAccount)->getJson('/api/v1/mobile/work-feed')->assertOk()
            ->assertJsonPath('data.0.work_type', WorkOrder::TYPE_P0)->assertJsonPath('data.0.p0.pasila_session_code', 'PSL-20261029-A-PUGUNG');
    }

    public function test_rejection_requires_a_manual_replacement_in_the_same_session(): void
    {
        $finding = $this->finding($this->session, 'LBSM BEDANA');
        $other = $this->finding($this->session, 'LBSM GUNUNG MAS');
        $item = $this->propose($finding);

        $this->actingAs($this->admin)->post(route('admin.p0.reject', $item), ['review_note' => 'singkat'])->assertSessionHasErrors('review_note');
        $this->actingAs($this->admin)->post(route('admin.p0.reject', $item), ['review_note' => 'Lokasi sudah ditangani warga'])->assertRedirect(route('admin.p0.show', $item));
        $item->refresh();
        $this->assertSame([P0Item::APPROVAL_DITOLAK, P0Item::REPLACEMENT_WAJIB, null, null, P0Item::STAGE_MENUNGGU_PENGGANTI], [$item->approval_status, $item->replacement_status, $item->active_source_key, $item->work_order_id, $item->stage()]);
        $this->assertSame(0, WorkOrder::query()->count(), 'Usulan ditolak tidak menjadi pekerjaan.');
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'p0_item')->where('action', AuditLog::ACTION_REJECTED)->count());

        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk()->assertSee('Pengganti wajib dibuat Admin: 1');
        $this->actingAs($this->admin)->get($this->pasilaUrl())->assertOk()
            ->assertSee('Ditolak, menunggu P0 pengganti')->assertSee('Target P0 shift: belum terpenuhi')->assertSee('Kewajiban pengganti Admin: 1 terbuka')->assertSee($item->code.' (ditolak)');

        // Pengganti hanya untuk sesi yang sama.
        $wjFinding = $this->finding($this->wayJeparaSession, 'REC BRAJA DEWA');
        $this->manual($this->wayJeparaSession, $wjFinding, ['replaces_p0_item_id' => $item->id])->assertSessionHasErrors('replaces_p0_item_id');

        $this->manual($this->session, $other, ['replaces_p0_item_id' => $item->id])->assertRedirect()->assertSessionHasNoErrors();
        $replacement = P0Item::query()->where('replaces_p0_item_id', $item->id)->sole();
        $this->assertSame([P0Item::ORIGIN_ADMIN, P0Item::APPROVAL_TIDAK_PERLU, WorkOrder::SOURCE_ADMIN_MANUAL, P0Item::STAGE_AKTIF], [$replacement->origin, $replacement->approval_status, $replacement->workOrder->source, $replacement->stage()]);
        $this->assertSame([P0Item::REPLACEMENT_TERPENUHI, P0Item::STAGE_DIGANTI], [$item->refresh()->replacement_status, $item->stage()]);
        // Pengganti memenuhi kewajiban penggantian, bukan target P0 selesai.
        $obligation = $this->obligation();
        $this->assertSame([0, false, 0], [$obligation['replacement_open'], $obligation['fulfilled'], $obligation['completed']]);
        $this->actingAs($this->admin)->get($this->pasilaUrl())->assertOk()
            ->assertSee('Target P0 shift: belum terpenuhi')->assertSee('Kewajiban pengganti Admin: tidak ada')->assertSee('Ditolak, pengganti sudah dibuat');

        // Satu penolakan hanya punya satu pengganti; sumber yang ditolak boleh dipakai lagi untuk P0 manual.
        $this->manual($this->session, $finding, ['replaces_p0_item_id' => $item->id])->assertSessionHasErrors('replaces_p0_item_id');
        $this->manual($this->session, $finding)->assertSessionHasNoErrors();
        $this->assertSame(2, WorkOrder::query()->where('work_type', WorkOrder::TYPE_P0)->count());
    }

    public function test_manual_p0_from_an_earlier_pasila_finding_or_a_validated_inspection_finding(): void
    {
        // Masalah tercatat sebelumnya: temuan PASILA dari sesi kemarin.
        $this->travelTo(Carbon::create(2026, 10, 28, 16, 5, 0, ShiftService::TIMEZONE));
        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
        $yesterday = PasilaSession::query()->where('posko_id', $this->posko->id)->whereHas('shiftInstance', fn ($q) => $q->where('shift_code', 'B'))->sole();
        $earlier = $this->finding($yesterday, 'LBSM GUNUNG MAS');
        $this->travelTo(Carbon::create(2026, 10, 29, 9, 0, 0, ShiftService::TIMEZONE));

        $this->manual($this->session, $earlier)->assertSessionHasNoErrors();
        $this->assertSame([P0Item::SOURCE_PASILA_FINDING, 'LBSM GUNUNG MAS', 'REOG'], [P0Item::query()->sole()->source_type, P0Item::query()->sole()->section_name, P0Item::query()->sole()->penyulang->kode_penyulang]);

        // Temuan sesi ini tidak boleh menjadi sumber P0 sesi kemarin (bukan masalah "sebelumnya").
        $today = $this->finding($this->session, 'LBSM BEDANA');
        $this->expectValidation(fn () => app(P0Service::class)->proposeSystem($yesterday, P0Item::SOURCE_PASILA_FINDING, $today->id, 'Uraian pekerjaan uji', []), 'sesi yang lebih baru');

        // Temuan inspeksi: hanya yang sudah divalidasi, punya Section milik Posko, dan belum ditindaklanjuti.
        $validated = $this->inspectionFinding('LBSM GUNUNG MAS', InspectionFinding::VALIDATION_DISETUJUI);
        $this->manual($this->session, $validated, ['source_type' => P0Item::SOURCE_INSPECTION_FINDING])->assertSessionHasNoErrors();
        $inspectionP0 = P0Item::query()->where('source_type', P0Item::SOURCE_INSPECTION_FINDING)->sole();
        $this->assertSame(['LBSM GUNUNG MAS', WorkOrderLocationTarget::TYPE_INSPEKSI], [$inspectionP0->section_name, $inspectionP0->workOrder->locationTarget->target_type]);

        $cases = [
            'belum divalidasi' => $this->inspectionFinding('LBSM GUNUNG MAS', InspectionFinding::VALIDATION_MENUNGGU),
            'tidak mencatat Section' => $this->inspectionFinding(null, InspectionFinding::VALIDATION_DISETUJUI),
            'milik Posko WAY JEPARA' => $this->inspectionFinding('REC BRAJA DEWA', InspectionFinding::VALIDATION_DISETUJUI),
        ];
        foreach ($cases as $message => $source) {
            $this->expectValidation(fn () => app(P0Service::class)->proposeSystem($this->session, P0Item::SOURCE_INSPECTION_FINDING, $source->id, 'Uraian pekerjaan uji', []), $message);
        }
        $this->assertSame(2, P0Item::query()->count());
    }

    public function test_source_of_another_posko_is_rejected_and_ownership_is_rechecked_on_approval(): void
    {
        $wjFinding = $this->finding($this->wayJeparaSession, 'REC BRAJA DEWA');
        $this->manual($this->session, $wjFinding)->assertSessionHasErrors('source');
        $this->assertStringContainsString('milik Posko lain', session('errors')->first('source'));

        $item = $this->propose($this->finding($this->session, 'LBSM BEDANA'));
        // Referensi resmi kemudian memindahkan LBSM BEDANA ke WAY JEPARA: ACC ditolak, usulan tetap menunggu.
        DB::table('section_pic_source_rows')->where('pemutus_raw', 'LBSM BEDANA')->update(['posko_pic_raw' => 'WAY JEPARA']);
        $this->actingAs($this->admin)->post(route('admin.p0.approve', $item), ['work_date' => '2026-10-29', 'shift_code' => 'A'])->assertSessionHasErrors('source');
        $this->assertStringContainsString('milik Posko WAY JEPARA, bukan Posko PUGUNG', session('errors')->first('source'));
        $this->assertSame([P0Item::APPROVAL_MENUNGGU, 0], [$item->refresh()->approval_status, WorkOrder::query()->count()]);
    }

    public function test_only_the_assigned_posko_executes_and_the_target_counts_only_completed_work(): void
    {
        $this->manual($this->session, $this->finding($this->session, 'LBSM BEDANA'))->assertSessionHasNoErrors();
        $item = P0Item::query()->sole();
        $wo = $item->workOrder;
        $this->assertFalse($this->obligation()['fulfilled'], 'P0 dibuat belum memenuhi target.');

        $this->api($this->fieldAccount($this->wayJeparaTeam, 'posko_wj_app'))->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertNotFound();
        $this->api($this->fieldAccount($this->harsusTeam, 'harsus_app'))->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertNotFound();
        $this->api($this->fieldAccount($this->inspeksiTeam, 'inspeksi_app'))->getJson('/api/v1/mobile/p0')->assertForbidden();

        $api = fn () => $this->api($this->pugungAccount);
        // Laporan selesai diverifikasi Admin lewat Web (tanpa header API).
        $adminWeb = function () {
            $this->flushHeaders();

            return $this->actingAs($this->admin);
        };
        $api()->postJson("/api/v1/mobile/work-orders/{$wo->id}/start")->assertOk()->assertJsonPath('data.status', 'DIKERJAKAN');
        $api()->postJson("/api/v1/mobile/work-orders/{$wo->id}/escalations", ['client_request_id' => 'esc-p0', 'target_team_type' => 'HARSUS', 'target_work_type' => 'PEKERJAAN_KHUSUS', 'reason_code' => 'BUTUH_PADAM', 'note' => 'Butuh pemadaman'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_team_type');
        $this->assertFalse($this->obligation()['fulfilled'], 'P0 sedang dikerjakan belum memenuhi target.');

        $api()->post("/api/v1/mobile/work-orders/{$wo->id}/reports", [
            'client_request_id' => 'p0-selesai', 'report_type' => WorkOrderReport::TYPE_PEKERJAAN_SELESAI, 'notes' => 'Ranting dipangkas',
            'latitude' => -5.2001, 'longitude' => 105.7001, 'personnel_ids' => [$this->poskoPerson->id],
            'photos_before' => [$this->photo('before.jpg')], 'photos_after' => [$this->photo('after.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.work_order.status', 'MENUNGGU_VERIFIKASI');
        $this->assertSame([P0Item::STAGE_MENUNGGU_VERIFIKASI, false], [$item->refresh()->stage(), $this->obligation()['fulfilled']]);

        $report = WorkOrderReport::query()->where('work_order_id', $wo->id)->sole();
        $adminWeb()->post(route('admin.reviews.reports.approve', $report), ['note' => 'Bukti lengkap'])->assertSessionHasNoErrors();
        $this->assertSame([WorkOrderStatus::Selesai, P0Item::STAGE_SELESAI], [$wo->refresh()->status, $item->refresh()->stage()]);
        $this->assertSame(['target' => 1, 'completed' => 1, 'fulfilled' => true], array_intersect_key($this->obligation(), ['target' => 0, 'completed' => 0, 'fulfilled' => 0]));
        $adminWeb()->get(route('admin.pasila.index'))->assertOk()->assertSee('data-p0-obligation="TERPENUHI"', false);
    }

    public function test_p0_cannot_be_cancelled_on_any_path_while_other_cancellation_rules_stay(): void
    {
        $this->manual($this->session, $this->finding($this->session, 'LBSM BEDANA'))->assertSessionHasNoErrors();
        $this->propose($this->finding($this->session, 'LBSM GUNUNG MAS'));
        $this->actingAs($this->admin)->post(route('admin.p0.approve', P0Item::query()->where('origin', P0Item::ORIGIN_SYSTEM)->sole()), ['work_date' => '2026-10-29', 'shift_code' => 'A'])->assertSessionHasNoErrors();
        [$manualWo, $systemWo] = [P0Item::query()->where('origin', P0Item::ORIGIN_ADMIN)->sole()->workOrder, P0Item::query()->where('origin', P0Item::ORIGIN_SYSTEM)->sole()->workOrder];

        foreach ([$manualWo, $systemWo] as $wo) {
            $this->assertFalse($wo->supportsAdminCancellation());
            // Jalur Web Admin.
            $this->actingAs($this->admin)->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Uji pembatalan P0'])->assertSessionHasErrors('status');
            // Jalur service langsung dan transisi status langsung.
            $this->expectValidation(fn () => app(WorkOrderLifecycleService::class)->cancel($wo, $this->admin, 'Uji pembatalan P0'), 'aturan pembatalannya belum disahkan');
            $this->expectValidation(fn () => app(WorkOrderLifecycleService::class)->transition($wo, WorkOrderStatus::Dibatalkan, $this->admin), 'kebijakan pembatalan P0 belum disahkan');
            $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->refresh()->status);
        }
        $this->actingAs($this->admin)->get(route('admin.work-orders.show', $manualWo))->assertOk()->assertDontSee(route('admin.work-orders.cancel', $manualWo));

        // Aturan pembatalan lain tidak berubah: WO APKUK dan WO HARSUS/ROW manual tanpa limpahan tetap dapat dibatalkan Admin.
        $this->assertTrue((new WorkOrder)->forceFill(['team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK, 'source' => WorkOrder::SOURCE_SYSTEM])->supportsAdminCancellation());
        $this->assertTrue((new WorkOrder)->forceFill(['team_type' => WorkOrder::TEAM_HARSUS, 'work_type' => WorkOrder::TYPE_PEKERJAAN_KHUSUS, 'source' => WorkOrder::SOURCE_ADMIN_MANUAL])->supportsAdminCancellation());
        $this->assertFalse((new WorkOrder)->forceFill(['team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS, 'source' => WorkOrder::SOURCE_SYSTEM])->supportsAdminCancellation());
    }

    public function test_admin_editor_cannot_move_a_p0_out_of_its_section_or_posko(): void
    {
        $this->manual($this->session, $this->finding($this->session, 'LBSM BEDANA'))->assertSessionHasNoErrors();
        $wo = P0Item::query()->sole()->workOrder;
        $editor = app(WorkOrderEditor::class);

        $this->expectValidation(fn () => $editor->update($wo, $this->admin, ['work_date' => '2026-10-29', 'shift_code' => 'A', 'section_name' => 'REC BRAJA DEWA'], 'Pindah Section'), 'tidak dapat diubah');
        $this->expectValidation(fn () => $editor->update($wo, $this->admin, ['work_date' => '2026-10-29', 'shift_code' => 'A', 'field_team_id' => $this->wayJeparaTeam->id], 'Tunjuk tim lain'), 'POSKO penanggung jawab WO');
        // Tim POSKO tanpa Posko juga ditolak untuk P0.
        $floating = FieldTeam::create(['code' => 'POSKO-TANPA-POSKO', 'name' => 'Tim tanpa Posko', 'role' => 'POSKO']);
        $this->expectValidation(fn () => $editor->update($wo, $this->admin, ['work_date' => '2026-10-29', 'shift_code' => 'A', 'field_team_id' => $floating->id], 'Tunjuk tim lain'), 'Posko pemilik sesi PASILA');
        // Jadwal tetap boleh diubah; Section tetap.
        $editor->update($wo, $this->admin, ['work_date' => '2026-10-29', 'shift_code' => 'B', 'section_name' => 'LBSM BEDANA', 'field_team_id' => $this->poskoTeam->id], 'Pindah ke Shift B');
        $this->assertSame(['B', 'LBSM BEDANA', $this->posko->id], [$wo->refresh()->shift_code, $wo->section_name, $wo->posko_id]);
    }

    public function test_p0_is_not_part_of_the_apkuk_quota(): void
    {
        $this->manual($this->session, $this->finding($this->session, 'LBSM BEDANA'))->assertSessionHasNoErrors();

        $scheduled = app(ApkukShiftQuota::class)->scheduled('2026-10-29', 'A', $this->posko->id);
        $this->assertSame(0, ($scheduled[$this->posko->id]['carry_over'] ?? collect())->count() + ($scheduled[$this->posko->id]['existing'] ?? collect())->count());
        $this->assertSame(1, WorkOrder::query()->where('work_type', WorkOrder::TYPE_P0)->where('shift_code', 'A')->count());
        $this->assertSame(0, WorkOrder::query()->apkuk()->count());
    }

    public function test_one_running_p0_per_source_and_repeated_requests_create_no_duplicate_work(): void
    {
        $finding = $this->finding($this->session, 'LBSM BEDANA');
        $this->propose($finding);
        $this->expectValidation(fn () => $this->propose($finding), 'sudah memiliki P0');
        $this->manual($this->session, $finding)->assertSessionHasErrors('source');

        $second = $this->finding($this->session, 'LBSM GUNUNG MAS');
        $this->manual($this->session, $second)->assertSessionHasNoErrors();
        $this->manual($this->session, $second)->assertSessionHasErrors('source');
        $this->assertSame([2, 1], [P0Item::query()->count(), WorkOrder::query()->count()]);
        $this->assertSame([1, 2], P0Item::query()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(2, AuditLog::query()->where('entity_type', 'p0_item')->where('action', AuditLog::ACTION_CREATED)->count());
    }

    public function test_without_a_valid_source_no_p0_is_created_and_the_obligation_stays_visible(): void
    {
        $service = app(P0Service::class);
        $this->assertSame([], $service->candidates($this->session));
        $result = $service->runStrategy($this->session);
        $this->assertSame('REFUSED', $result['status']);
        $this->assertStringContainsString('belum siap', $result['message']);
        $this->assertFalse($service->strategy()->isMachineLearning());
        $this->assertSame(0, P0Item::query()->count());

        // PASILA boleh nihil walau kewajiban P0 belum terpenuhi.
        app(PasilaFindingService::class)->recordNilReport($this->session, $this->pugungAccount, [
            'client_request_id' => 'nil-p0', 'note' => 'Patroli LBSM BEDANA aman tanpa temuan', 'latitude' => -5.21, 'longitude' => 105.71,
            'occurred_at' => '2026-10-29T08:50:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
        ]);
        $this->assertSame(PasilaSession::PATROL_NIHIL, $this->session->refresh()->patrolStatus());

        $this->actingAs($this->admin)->get($this->pasilaUrl())->assertOk()
            ->assertSee('Target P0 shift: belum terpenuhi')->assertSee('Belum ada P0 untuk sesi ini.')->assertSee('Belum ada sumber P0 yang sah.')
            ->assertSee('Nihil (dilaporkan petugas)');
        $this->actingAs($this->admin)->get(route('admin.p0.index'))->assertOk()->assertSee('Usulan P0 SYSTEM otomatis belum siap')->assertSee('Belum ada P0 untuk filter ini');
    }

    public function test_admin_monitor_detail_and_access(): void
    {
        $pending = $this->propose($this->finding($this->session, 'LBSM BEDANA'));
        $this->manual($this->session, $this->finding($this->session, 'LBSM GUNUNG MAS'))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.p0.index'))->assertOk()
            ->assertSee('data-stage="MENUNGGU_ACC"', false)->assertSee('data-stage="AKTIF"', false)
            ->assertSee('PSL-20261029-A-PUGUNG')->assertSee('Usulan SYSTEM')->assertSee('Manual Admin')->assertSee('Belum ada WO')->assertSee('Usulan belum menjadi pekerjaan');
        $this->actingAs($this->admin)->get(route('admin.p0.index', ['stage' => P0Item::STAGE_MENUNGGU_ACC]))->assertOk()
            ->assertSee($pending->code)->assertDontSee('P0-20261029-A-PUGUNG-02');
        $this->actingAs($this->admin)->get(route('admin.p0.show', $pending))->assertOk()
            ->assertSee('ACC dan buat WO P0')->assertSee('Tolak usulan')->assertSee('Temuan PASILA')->assertSee('bukan ML')->assertSee('Ranting bambu menyentuh JTM');
        $this->actingAs($this->admin)->get('/admin/p0/bukan-uuid')->assertNotFound();

        $field = $this->pugungAccount;
        $this->actingAs($field)->get(route('admin.p0.index'))->assertForbidden();
        $this->actingAs($field)->post(route('admin.p0.approve', $pending), ['work_date' => '2026-10-29', 'shift_code' => 'A'])->assertForbidden();
        $this->actingAs($field)->post(route('admin.pasila.p0.store', $this->session), [])->assertForbidden();
        $this->assertSame(P0Item::APPROVAL_MENUNGGU, $pending->refresh()->approval_status);
    }

    private function propose(PasilaFinding $finding): P0Item
    {
        return app(P0Service::class)->proposeSystem($this->session, P0Item::SOURCE_PASILA_FINDING, $finding->id, 'Pangkas ranting bambu yang menyentuh JTM', [
            'strategy' => 'UJI_SIMULASI', 'version' => '1', 'reasons' => ['Usulan simulasi untuk menguji alur ACC.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function manual(PasilaSession $session, PasilaFinding|InspectionFinding $source, array $override = []): TestResponse
    {
        $type = $override['source_type'] ?? P0Item::SOURCE_PASILA_FINDING;
        unset($override['source_type']);

        return $this->actingAs($this->admin)->from(route('admin.p0.index'))->post(route('admin.pasila.p0.store', $session), $override + [
            'source' => $type.':'.$source->id,
            'description' => 'Tindak lanjut temuan oleh tim POSKO',
            'work_date' => '2026-10-29',
            'shift_code' => 'A',
        ]);
    }

    private function finding(PasilaSession $session, string $section): PasilaFinding
    {
        $entry = SectionPoskoMap::load()->entry($section);
        $session->loadMissing('shiftInstance');

        return PasilaFinding::create([
            'pasila_session_id' => $session->id,
            'sequence' => (int) PasilaFinding::query()->where('pasila_session_id', $session->id)->max('sequence') + 1,
            'posko_id' => $session->posko_id,
            'penyulang_id' => $entry['penyulang_model']->id,
            'section_key' => $entry['key'],
            'section_name' => $entry['section'],
            'finding_code' => 'POHON_DEKAT_JTM',
            'description' => 'Ranting bambu menyentuh JTM',
            'latitude' => -5.2001,
            'longitude' => 105.7001,
            'coordinate_source' => PasilaFinding::COORDINATE_DEVICE,
            'occurred_at' => $session->shiftInstance->starts_at->copy()->addMinutes(30),
            'received_at' => now(),
            'status' => PasilaFinding::STATUS_TERCATAT,
            'source' => PasilaFinding::SOURCE_MOBILE,
            'submitted_by_field_team_id' => $session->posko_id === $this->posko->id ? $this->poskoTeam->id : $this->wayJeparaTeam->id,
            'submitted_by_user_id' => $this->admin->id,
        ]);
    }

    private function inspectionFinding(?string $section, string $validation): InspectionFinding
    {
        static $n = 0;
        $n++;
        $inspection = Inspection::create([
            'code' => 'INS-UJI-'.$n, 'field_team_id' => $this->inspeksiTeam->id, 'penyulang_id' => $this->penyulang->id, 'section_name' => $section,
            'visited_at' => now()->subDay(), 'has_finding' => true, 'source' => Inspection::SOURCE_ADMIN, 'submitted_by_user_id' => $this->admin->id,
        ]);

        return InspectionFinding::create([
            'inspection_id' => $inspection->id, 'finding_code' => 'POHON_DEKAT', 'description' => 'Pohon condong ke jaringan', 'latitude' => -5.25, 'longitude' => 105.75,
            'occurred_at' => now()->subDay(), 'validation_status' => $validation, 'validated_at' => $validation === InspectionFinding::VALIDATION_DISETUJUI ? now() : null,
        ]);
    }

    /**
     * @return array{target: int, completed: int, fulfilled: bool, stages: array<string, int>}
     */
    private function obligation(): array
    {
        return P0Service::obligation($this->session->p0Items()->with('workOrder')->get());
    }

    private function expectValidation(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("ValidationException diharapkan: {$message}");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, collect($e->errors())->flatten()->implode(' '));
        }
    }

    private function pasilaUrl(): string
    {
        return route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']);
    }

    private function api(User $account): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$account->createToken('HP', ['field'])->plainTextToken)->withHeader('Accept', 'application/json');
    }
}
