<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\PasilaClosure;
use App\Models\PasilaFinding;
use App\Models\PasilaNilReport;
use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * B3.4 — penutupan sesi PASILA oleh tim POSKO. Data simulasi.
 * Waktu uji: 29 Okt 2026 09:00 WIB (Shift A hari operasional 29: 08.00–16.00 WIB).
 */
class PasilaClosureTest extends OperationsTestCase
{
    private FieldTeam $wayJeparaTeam;

    private User $account;

    private PasilaSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 29, 9, 0, 0, ShiftService::TIMEZONE));
        config(['pasila.opener.enabled' => true]);
        $wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);
        $this->wayJeparaTeam = FieldTeam::create(['code' => 'POSKO-WJ', 'name' => 'Tim Way Jepara', 'role' => 'POSKO', 'posko_id' => $wayJepara->id]);
        $this->wayJeparaTeam->personnel()->create(['name' => 'Wati Jepara']);
        foreach ([['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], [null, 'REC BRAJA DEWA', 'WAY JEPARA'], ['BEDANA', 'LBSM BEDANA', 'PUGUNG']] as $i => [$p, $s, $k]) {
            DB::table('section_pic_source_rows')->insert(['penyulang_raw' => $p, 'pemutus_raw' => $s, 'posko_pic_raw' => $k, 'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM']);
        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
        $this->session = PasilaSession::query()->where('posko_id', $this->posko->id)->sole();
        $this->account = $this->fieldAccount($this->poskoTeam, 'posko_pugung_app');
    }

    public function test_closing_after_many_findings_records_outcome_snapshot_audit_and_is_idempotent(): void
    {
        $this->finding(['client_request_id' => 'f-1'])->assertCreated();
        $this->finding(['client_request_id' => 'f-2', 'section' => 'LBSM GUNUNG MAS', 'latitude' => -5.31])->assertCreated();

        $this->close()->assertCreated()
            ->assertJsonPath('data.status', PasilaSession::STATUS_DITUTUP)
            ->assertJsonPath('data.patrol_status', PasilaSession::PATROL_ADA_TEMUAN)
            ->assertJsonPath('data.closure.outcome', PasilaClosure::OUTCOME_ADA_TEMUAN)
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonPath('meta.warnings.P0_TARGET_BELUM_TERPENUHI', 'Target P0 shift belum terpenuhi; status tetap tercatat belum terpenuhi.');

        $closure = PasilaClosure::query()->sole();
        $this->assertSame([2, null, $this->account->id, $this->poskoTeam->id], [$closure->finding_count, $closure->pasila_nil_report_id, $closure->submitted_by_user_id, $closure->submitted_by_field_team_id]);
        $this->assertSame([['id' => $this->poskoPerson->id, 'name' => 'Budi Posko']], $closure->personnel);
        $this->assertSame(['finding_count' => 2, 'nil_report_id' => null], $closure->snapshot['patrol']);
        $this->assertSame([1, 0, false], [$closure->snapshot['p0']['target'], $closure->snapshot['p0']['completed'], $closure->snapshot['p0']['fulfilled']]);
        $this->assertSame('2026-10-29 01:30:00', $closure->occurred_at->toDateTimeString(), '08.30 WIB disimpan UTC.');
        $audit = AuditLog::query()->where('entity_type', 'pasila_session')->where('action', AuditLog::ACTION_CLOSED)->sole();
        $this->assertSame([PasilaSession::STATUS_DIBUKA, PasilaSession::STATUS_DITUTUP, PasilaClosure::OUTCOME_ADA_TEMUAN], [$audit->before['status'], $audit->after['status'], $audit->after['outcome']]);

        // Retry offline dengan kunci sama: penutupan yang ada; kunci baru: ditolak (sudah ditutup); isi lain dengan kunci sama: 422.
        $this->close()->assertCreated()->assertJsonPath('meta.idempotent_replay', true);
        $this->close(['client_request_id' => 'close-2'])->assertStatus(409)->assertJsonFragment(['message' => 'Sesi PSL-20261029-A-PUGUNG sudah ditutup pada 29/10/2026 09:00 WIB.']);
        $this->close(['note' => 'Isi berbeda dengan kunci sama'])->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
        $this->assertSame(1, PasilaClosure::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_CLOSED)->count());
    }

    public function test_explicit_nil_report_closes_as_nil(): void
    {
        $this->nil()->assertCreated();

        $this->close()->assertCreated()->assertJsonPath('data.closure.outcome', PasilaClosure::OUTCOME_NIHIL)->assertJsonPath('data.patrol_status', PasilaSession::PATROL_NIHIL);
        $closure = PasilaClosure::query()->sole();
        $this->assertSame([0, PasilaNilReport::query()->sole()->id], [$closure->finding_count, $closure->pasila_nil_report_id]);
        $this->assertSame(0, PasilaFinding::query()->count(), 'Penutupan nihil tidak membuat temuan.');
    }

    public function test_session_without_patrol_report_cannot_be_closed_and_times_are_validated(): void
    {
        $this->close()->assertStatus(409)->assertJsonPath('message', 'Sesi PSL-20261029-A-PUGUNG belum dapat ditutup: belum ada laporan patroli yang diterima server (temuan atau laporan nihil). Kirim laporan patroli lebih dulu.');
        $this->assertSame([PasilaSession::STATUS_DIBUKA, 0], [$this->session->refresh()->status, PasilaClosure::query()->count()]);

        $this->nil()->assertCreated();
        $this->close(['client_request_id' => 'c-early', 'occurred_at' => '2026-10-29T07:59:00+07:00'])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->close(['client_request_id' => 'c-future', 'occurred_at' => '2026-10-29T09:30:00+07:00'])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->close(['client_request_id' => 'c-names', 'personnel_ids' => [], 'personnel_names' => ['Orang Lain']])->assertUnprocessable()->assertJsonValidationErrors('personnel_ids');
        $this->assertSame(0, PasilaClosure::query()->count());
    }

    public function test_closing_does_not_wait_for_p0_target_or_pending_apkuk_reschedule_and_obligations_continue(): void
    {
        $pending = $this->apkukWork('WO-RESCHEDULE', WorkOrderStatus::RescheduleDiajukan);
        $reschedule = WorkOrderReschedule::create([
            'work_order_id' => $pending->id, 'reason_code' => 'CUACA', 'note' => 'Hujan deras', 'from_work_date' => '2026-10-29',
            'from_shift_code' => 'A', 'proposed_work_date' => '2026-10-29', 'proposed_shift_code' => 'B', 'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);
        $this->apkukWork('WO-DITUGASKAN', WorkOrderStatus::Ditugaskan);
        $this->nil()->assertCreated();

        $this->close()->assertCreated()
            ->assertJsonPath('meta.warnings.APKUK_RESCHEDULE_MENUNGGU_ACC', '1 WO APKUK menunggu ACC reschedule; kewajiban WO tetap berjalan.')
            ->assertJsonPath('meta.warnings.APKUK_BELUM_SELESAI', '2 WO APKUK belum selesai.');
        $this->assertSame(['total' => 2, 'selesai' => 0, 'belum_selesai' => 2, 'reschedule_menunggu_acc' => 1], PasilaClosure::query()->sole()->snapshot['apkuk']);
        $this->assertSame(WorkOrderStatus::RescheduleDiajukan, $pending->refresh()->status, 'Penutupan tidak mengubah WO APKUK.');

        // Reschedule ditolak setelah PASILA ditutup: kewajiban WO kembali berjalan, sesi tetap ditutup.
        $this->flushHeaders();
        $this->actingAs($this->admin)->post(route('admin.reviews.reschedules.reject', $reschedule), ['review_note' => 'Cuaca cerah, kerjakan hari ini'])->assertSessionHasNoErrors();
        $this->assertSame([WorkOrderStatus::Ditugaskan, PasilaSession::STATUS_DITUTUP], [$pending->refresh()->status, $this->session->refresh()->status]);

        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk()
            ->assertSee('data-session-status="DITUTUP"', false)->assertSee('Ditutup nihil')
            ->assertSee('data-patrol-status="NIHIL"', false)->assertSee('Target belum terpenuhi')
            ->assertSeeInOrder(['data-posko="PUGUNG"', '2 WO tercatat · 2 aktif'], false);
        $this->assertSame(1, $this->get(route('admin.pasila.index'))->viewData('totals')['closed']);
    }

    public function test_late_offline_finding_after_a_nil_closure_is_kept_and_flagged(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 29, 15, 40, 0, ShiftService::TIMEZONE));
        $this->nil(['occurred_at' => '2026-10-29T15:30:00+07:00'])->assertCreated();
        $this->close(['occurred_at' => '2026-10-29T15:35:00+07:00'])->assertCreated();

        // Temuan yang terekam 15.20 baru terkirim 16.40 (setelah shift dan setelah ditutup): diterima, tidak dibuang.
        $this->travelTo(Carbon::create(2026, 10, 29, 16, 40, 0, ShiftService::TIMEZONE));
        $late = ['client_request_id' => 'late-1', 'occurred_at' => '2026-10-29T15:20:00+07:00'];
        $this->finding($late)->assertCreated();
        $this->finding($late)->assertCreated()->assertJsonPath('meta.idempotent_replay', true);

        $this->assertSame([1, PasilaNilReport::STATUS_DIGANTIKAN], [PasilaFinding::query()->count(), PasilaNilReport::query()->sole()->status]);
        $closure = PasilaClosure::query()->sole();
        $this->assertSame([PasilaClosure::OUTCOME_NIHIL, 0], [$closure->outcome, $closure->finding_count], 'Catatan penutupan tidak ditulis ulang.');
        $this->assertSame(['TEMUAN_SETELAH_DITUTUP', 'NIHIL_TERBANTAH'], array_keys($this->session->refresh()->closureFlags()));
        $this->assertSame('Diterima setelah sesi PASILA ditutup.', AuditLog::query()->where('entity_type', 'pasila_finding')->sole()->reason);

        $this->api()->getJson("/api/v1/mobile/pasila/{$this->session->id}")->assertOk()
            ->assertJsonPath('data.patrol_status', PasilaSession::PATROL_ADA_TEMUAN)
            ->assertJsonPath('data.closure.flags', ['TEMUAN_SETELAH_DITUTUP', 'NIHIL_TERBANTAH']);
        $this->flushHeaders();
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))->assertOk()
            ->assertSee('Sesi ditutup · Ditutup nihil')->assertSee('1 temuan diterima setelah sesi ditutup')
            ->assertSee('Ditutup nihil, kemudian ada temuan (laporan nihil digantikan)')->assertSee('Saat ditutup: 0 temuan');
    }

    public function test_closure_after_shift_end_is_recorded_and_flagged(): void
    {
        $this->nil()->assertCreated();
        $this->travelTo(Carbon::create(2026, 10, 29, 16, 10, 0, ShiftService::TIMEZONE));

        $this->close(['occurred_at' => '2026-10-29T16:05:00+07:00'])->assertCreated();
        $this->assertSame(['DITUTUP_SETELAH_SHIFT'], array_keys($this->session->refresh()->closureFlags()));
    }

    public function test_other_posko_and_other_roles_cannot_close_and_section_is_locked_after_closure(): void
    {
        $this->nil()->assertCreated();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/mobile/pasila/{$this->session->id}/close")->assertUnauthorized();
        $this->close(['personnel_ids' => [$this->wayJeparaTeam->personnel()->value('id')]], $this->fieldAccount($this->wayJeparaTeam, 'posko_wj_app'))->assertNotFound();
        $this->close([], $this->fieldAccount($this->harsusTeam, 'harsus_app'))->assertForbidden();
        $this->assertSame(0, PasilaClosure::query()->count());

        $this->close()->assertCreated();
        $this->flushHeaders();
        $this->actingAs($this->admin)->post(route('admin.pasila.section', $this->session), ['section' => 'LBSM GUNUNG MAS', 'reason' => 'Ganti Section setelah ditutup'])
            ->assertSessionHasErrors('section');
        $this->assertStringContainsString('sudah ditutup', session('errors')->first('section'));
    }

    private function api(?User $account = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.($account ?? $this->account)->createToken('HP', ['field'])->plainTextToken)->withHeader('Accept', 'application/json');
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function close(array $override = [], ?User $account = null): TestResponse
    {
        return $this->api($account)->postJson("/api/v1/mobile/pasila/{$this->session->id}/close", array_merge([
            'client_request_id' => 'close-1',
            'occurred_at' => '2026-10-29T08:30:00+07:00',
            'personnel_ids' => [$this->poskoPerson->id],
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function nil(array $override = []): TestResponse
    {
        return $this->api()->postJson("/api/v1/mobile/pasila/{$this->session->id}/nil-report", array_merge([
            'client_request_id' => 'nil-1', 'note' => 'Patroli LBSM BEDANA sampai ujung, kondisi aman', 'latitude' => -5.21, 'longitude' => 105.71,
            'occurred_at' => '2026-10-29T08:20:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function finding(array $override = []): TestResponse
    {
        return $this->api()->post("/api/v1/mobile/pasila/{$this->session->id}/findings", array_merge([
            'client_request_id' => 'finding-1', 'finding_code' => 'POHON_DEKAT_JTM', 'description' => 'Ranting bambu menyentuh JTM',
            'latitude' => -5.2001, 'longitude' => 105.7001, 'occurred_at' => '2026-10-29T08:15:00+07:00',
            'personnel_ids' => [$this->poskoPerson->id], 'photos' => [$this->photo('temuan.jpg')],
        ], $override));
    }

    private function apkukWork(string $code, WorkOrderStatus $status): WorkOrder
    {
        return tap((new WorkOrder)->forceFill([
            'code' => $code, 'team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK, 'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => $status, 'posko_id' => $this->posko->id, 'work_date' => '2026-10-29', 'shift_code' => 'A',
        ]))->save();
    }
}
