<?php

namespace Tests\Feature\Operations;

use App\Models\FieldTeam;
use App\Models\PasilaFinding;
use App\Models\PasilaSession;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Pasila\P0Service;
use App\Services\Pasila\PasilaShiftOpener;
use Illuminate\Support\Facades\DB;

/**
 * B3.5 — kontrak API mobile POSKO seperti terdokumentasi di docs/MOBILE_API_GUIDE.md.
 * Bila tes ini gagal karena bentuk respons berubah, perbarui panduan untuk tim aplikasi.
 * Data simulasi; waktu 28 Okt 2026 09:00 WIB (Shift A).
 */
class MobileApiContractTest extends OperationsTestCase
{
    private const WO_SUMMARY = [
        'id', 'code', 'team_type', 'work_type', 'source', 'status', 'status_label', 'is_overdue', 'work_date', 'shift_code',
        'scheduled_start_at', 'due_at', 'penyulang' => ['id', 'name'], 'posko' => ['id', 'name'], 'section_name',
        'target' => ['label', 'latitude', 'longitude', 'google_maps_url', 'apkuk_target_quantity'], 'report_types', 'notes', 'p0',
    ];

    private const SESSION_SUMMARY = [
        'id', 'code', 'operational_date', 'shift_code', 'starts_at', 'ends_at', 'is_current', 'status', 'section_status',
        'section', 'patrol_status', 'finding_count', 'closure',
    ];

    private const NOT_FOUND_SESSION = ['message' => 'Sesi PASILA tidak ditemukan untuk Posko tim ini.'];

    private User $account;

    private PasilaSession $session;

    private PasilaSession $otherSession;

    protected function setUp(): void
    {
        parent::setUp();

        // Bentuk galat seperti di production (APP_DEBUG=false): tanpa exception/trace.
        config(['pasila.opener.enabled' => true, 'app.debug' => false]);
        $other = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        FieldTeam::create(['code' => 'POSKO-WJ', 'name' => 'Tim Way Jepara', 'role' => 'POSKO', 'posko_id' => $other->id]);
        foreach ([['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], ['REOG', 'REC BRAJA DEWA', 'WAY JEPARA']] as $i => [$p, $s, $k]) {
            DB::table('section_pic_source_rows')->insert(['penyulang_raw' => $p, 'pemutus_raw' => $s, 'posko_pic_raw' => $k, 'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM']);
        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
        $this->session = PasilaSession::query()->where('posko_id', $this->posko->id)->sole();
        $this->otherSession = PasilaSession::query()->where('posko_id', $other->id)->sole();
        $this->account = $this->fieldAccount($this->poskoTeam, 'posko_pugung_app');
    }

    public function test_login_me_logout_and_error_envelopes(): void
    {
        $this->postJson('/api/v1/mobile/auth/login', ['username' => 'posko_pugung_app', 'password' => 'salah', 'device_name' => 'HP'])
            ->assertUnprocessable()->assertExactJson(['message' => 'Username atau kata sandi salah.', 'errors' => ['username' => ['Username atau kata sandi salah.']]]);

        $token = $this->postJson('/api/v1/mobile/auth/login', ['username' => 'posko_pugung_app', 'password' => 'rahasia123', 'device_name' => 'HP'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'account' => ['id', 'username', 'name', 'role', 'team' => ['id', 'code', 'name', 'role', 'posko_id']]], 'meta' => ['server_time']])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.account.role', 'POSKO')
            ->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/mobile/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->withToken($token)->getJson('/api/v1/mobile/me')->assertOk()->assertJsonPath('data.team.posko_id', $this->posko->id);
        $this->withToken($token)->postJson('/api/v1/mobile/auth/logout')->assertOk()->assertExactJson(['data' => ['logged_out' => true], 'meta' => ['server_time' => now()->toIso8601String()]]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/mobile/me')->assertUnauthorized();

        // Akun dinonaktifkan Admin: token lama ditolak 403 dengan errors.auth (aplikasi harus keluar).
        $this->account->forceFill(['is_active' => false])->save();
        $this->api()->getJson('/api/v1/mobile/me')->assertForbidden()->assertJsonStructure(['message', 'errors' => ['auth']]);
    }

    public function test_bootstrap_work_order_feed_actions_and_conflicts(): void
    {
        $this->api()->getJson('/api/v1/mobile/bootstrap')->assertOk()->assertJsonStructure(['data' => [
            'team' => ['id', 'code', 'name', 'role', 'posko' => ['id', 'name']],
            'personnel' => [['id', 'code', 'name']],
            'reference_codes' => ['RESCHEDULE_REASON' => [['code', 'label']], 'ESCALATION_REASON', 'INSPECTION_FINDING', 'PASILA_FINDING' => [['code', 'label']]],
            'issue_types' => [['code', 'label']],
            'shifts' => [['code', 'name', 'time', 'index']],
            'current_shift' => ['code', 'name', 'time', 'index', 'operational_date'],
            'penyulangs' => [['id', 'code', 'name']],
        ], 'meta' => ['server_time']]);

        $done = $this->createApkukWorkOrder($this->apkuk());
        $later = $this->createApkukWorkOrder($this->apkuk());
        $this->api()->getJson('/api/v1/mobile/work-feed')->assertOk()
            ->assertJsonStructure(['data' => [self::WO_SUMMARY], 'meta' => ['scope', 'page', 'last_page', 'total', 'server_time']])
            ->assertJsonPath('meta.scope', 'active')->assertJsonPath('meta.total', 2);
        $this->api()->getJson("/api/v1/mobile/work-orders/{$done->id}")->assertOk()
            ->assertJsonStructure(['data' => [...self::WO_SUMMARY, 'reports', 'reschedules', 'escalations', 'issues']])
            ->assertJsonPath('data.report_types', ['PEMASANGAN_SELESAI', 'TEMUAN_SUDAH_LENGKAP']);

        $this->api()->postJson("/api/v1/mobile/work-orders/{$done->id}/start")->assertOk()->assertJsonPath('data.status', 'DIKERJAKAN');
        $report = ['client_request_id' => 'rpt-1', 'report_type' => 'PEMASANGAN_SELESAI', 'installed_quantity' => 3, 'latitude' => -5.2, 'longitude' => 105.7,
            'occurred_at' => '2026-10-28T08:45:00+07:00', 'personnel_ids' => [$this->poskoPerson->id]];
        $photos = ['photos_before' => [$this->photo('b.jpg')], 'photos_after' => [$this->photo('a.jpg')]];
        $this->api()->post("/api/v1/mobile/work-orders/{$done->id}/reports", $report + $photos)->assertCreated()
            ->assertJsonStructure(['data' => ['report_id', 'verification_status', 'work_order' => self::WO_SUMMARY], 'meta' => ['server_time', 'idempotent_replay']])
            ->assertJsonPath('data.verification_status', 'TIDAK_PERLU')->assertJsonPath('data.work_order.status', 'SELESAI')->assertJsonPath('meta.idempotent_replay', false);
        $this->api()->post("/api/v1/mobile/work-orders/{$done->id}/reports", $report + $photos)->assertCreated()->assertJsonPath('meta.idempotent_replay', true);
        $this->api()->post("/api/v1/mobile/work-orders/{$done->id}/reports", ['installed_quantity' => 2] + $report + $photos)
            ->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
        $this->api()->postJson("/api/v1/mobile/work-orders/{$done->id}/start")->assertStatus(409)
            ->assertExactJson(['message' => 'WO berstatus Selesai; tindakan ini tidak dapat dilakukan.', 'errors' => ['workflow' => ['WO berstatus Selesai; tindakan ini tidak dapat dilakukan.']]]);

        $this->api()->postJson("/api/v1/mobile/work-orders/{$later->id}/reschedules", [
            'client_request_id' => 'rs-1', 'reason_code' => 'CUACA', 'note' => 'Hujan deras, jalan licin', 'proposed_work_date' => '2026-10-28', 'proposed_shift_code' => 'B',
        ])->assertCreated()->assertJsonStructure(['data' => ['reschedule_id', 'status'], 'meta' => ['server_time', 'idempotent_replay']])->assertJsonPath('data.status', 'MENUNGGU_ACC');

        $this->api()->getJson('/api/v1/mobile/work-feed?scope=history')->assertOk()->assertJsonPath('meta.scope', 'history')->assertJsonPath('data.0.code', $done->code);
        $this->api()->getJson('/api/v1/mobile/work-orders/00000000-0000-0000-0000-000000000000')->assertNotFound()->assertExactJson(['message' => 'WO tidak ditemukan.']);
    }

    public function test_pasila_findings_closure_and_p0_shapes(): void
    {
        $this->api()->getJson('/api/v1/mobile/pasila')->assertOk()
            ->assertJsonStructure(['data' => [self::SESSION_SUMMARY], 'meta' => ['page', 'last_page', 'server_time']])
            ->assertJsonPath('data.0.section', ['section' => 'LBSM GUNUNG MAS', 'penyulang' => 'REOG'])
            ->assertJsonPath('data.0.closure', null);
        $this->api()->getJson("/api/v1/mobile/pasila/{$this->session->id}")->assertOk()
            ->assertJsonStructure(['data' => [...self::SESSION_SUMMARY, 'sections' => [['section', 'penyulang']], 'finding_codes' => [['code', 'label']], 'findings', 'nil_report']]);

        $this->api()->post("/api/v1/mobile/pasila/{$this->session->id}/findings", [
            'client_request_id' => 'fd-1', 'finding_code' => 'POHON_DEKAT_JTM', 'description' => 'Dahan bambu menyentuh JTM', 'latitude' => -5.21, 'longitude' => 105.72,
            'occurred_at' => '2026-10-28T08:30:00+07:00', 'personnel_ids' => [$this->poskoPerson->id], 'photos' => [$this->photo('t.jpg')],
        ])->assertCreated()->assertJsonStructure(['data' => [
            'id', 'sequence', 'finding_code', 'description', 'section', 'penyulang_id', 'keypoint_id', 'asset_label', 'latitude', 'longitude',
            'gps_accuracy_m', 'occurred_at', 'received_at', 'status', 'photo_count', 'personnel' => [['id', 'name']],
        ], 'meta' => ['server_time', 'idempotent_replay']]);

        $this->api()->postJson("/api/v1/mobile/pasila/{$this->session->id}/close", [
            'client_request_id' => 'cl-1', 'occurred_at' => '2026-10-28T08:55:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
        ])->assertCreated()
            ->assertJsonStructure(['data' => [...self::SESSION_SUMMARY, 'closure' => ['outcome', 'occurred_at', 'received_at', 'flags']], 'meta' => ['server_time', 'idempotent_replay', 'warnings']])
            ->assertJsonPath('data.status', 'DITUTUP')
            ->assertJsonPath('meta.warnings.P0_TARGET_BELUM_TERPENUHI', 'Target P0 shift belum terpenuhi; status tetap tercatat belum terpenuhi.');

        app(P0Service::class)->createManual($this->session, $this->admin, [
            'source_type' => 'PASILA_FINDING', 'source_id' => PasilaFinding::query()->sole()->id, 'description' => 'Pangkas dahan bambu', 'work_date' => '2026-10-28', 'shift_code' => 'A',
        ]);
        $p0 = $this->api()->getJson('/api/v1/mobile/p0')->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'code', 'pasila_session' => ['id', 'code'], 'stage', 'source_type', 'section', 'penyulang', 'description',
                'work_order' => ['id', 'code', 'status', 'work_date', 'shift_code'],
            ]], 'meta' => ['page', 'last_page', 'server_time']])
            ->assertJsonPath('data.0.stage', 'AKTIF');
        $this->api()->getJson('/api/v1/mobile/work-orders/'.$p0->json('data.0.work_order.id'))->assertOk()
            ->assertJsonPath('data.work_type', 'P0')->assertJsonPath('data.report_types', ['PEKERJAAN_SELESAI'])
            ->assertJsonStructure(['data' => ['p0' => ['code', 'pasila_session_code', 'source_type']]]);
    }

    /**
     * Tanpa peringatan, meta.warnings tetap objek JSON `{}` (sebelum B3.5 terkirim sebagai array `[]`).
     */
    public function test_closure_without_warnings_returns_an_empty_object(): void
    {
        $this->api()->post("/api/v1/mobile/pasila/{$this->session->id}/findings", [
            'client_request_id' => 'fd-1', 'finding_code' => 'POHON_DEKAT_JTM', 'description' => 'Dahan bambu menyentuh JTM', 'latitude' => -5.21, 'longitude' => 105.72,
            'occurred_at' => '2026-10-28T08:30:00+07:00', 'personnel_ids' => [$this->poskoPerson->id], 'photos' => [$this->photo('t.jpg')],
        ])->assertCreated();
        $item = app(P0Service::class)->createManual($this->session, $this->admin, [
            'source_type' => 'PASILA_FINDING', 'source_id' => PasilaFinding::query()->sole()->id, 'description' => 'Pangkas dahan bambu', 'work_date' => '2026-10-28', 'shift_code' => 'A',
        ]);
        $report = $this->api()->post("/api/v1/mobile/work-orders/{$item->work_order_id}/reports", [
            'client_request_id' => 'p0-1', 'report_type' => 'PEKERJAAN_SELESAI', 'latitude' => -5.21, 'longitude' => 105.72,
            'occurred_at' => '2026-10-28T08:50:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
            'photos_before' => [$this->photo('b.jpg')], 'photos_after' => [$this->photo('a.jpg')],
        ])->assertCreated()->assertJsonPath('data.work_order.status', 'MENUNGGU_VERIFIKASI')->json('data.report_id');
        $this->flushHeaders();
        $this->actingAs($this->admin)->post(route('admin.reviews.reports.approve', $report), ['note' => 'Bukti lengkap'])->assertSessionHasNoErrors();

        $close = $this->api()->postJson("/api/v1/mobile/pasila/{$this->session->id}/close", [
            'client_request_id' => 'cl-1', 'occurred_at' => '2026-10-28T08:55:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
        ])->assertCreated();
        $this->assertStringContainsString('"warnings":{}', $close->getContent());
        $this->assertSame([], $close->json('meta.warnings'));
    }

    /**
     * Sesi Posko lain dan sesi yang tidak ada dibalas 404 yang sama, tanpa nama kelas model.
     * Sebelum B3.5 sesi yang tidak ada dibalas "No query results for model [App\Models\PasilaSession] ...".
     */
    public function test_session_outside_scope_and_missing_session_give_the_same_404(): void
    {
        $missing = '00000000-0000-0000-0000-000000000000';
        foreach ([$this->otherSession->id, $missing] as $id) {
            $this->api()->getJson("/api/v1/mobile/pasila/{$id}")->assertNotFound()->assertExactJson(self::NOT_FOUND_SESSION);
            $this->api()->postJson("/api/v1/mobile/pasila/{$id}/nil-report", [])->assertNotFound()->assertExactJson(self::NOT_FOUND_SESSION);
            $this->api()->postJson("/api/v1/mobile/pasila/{$id}/close", [])->assertNotFound()->assertExactJson(self::NOT_FOUND_SESSION);
            $this->api()->post("/api/v1/mobile/pasila/{$id}/findings", [])->assertNotFound()->assertExactJson(self::NOT_FOUND_SESSION);
        }

        // Akun bukan POSKO ditolak sebelum sesi dicari: 403, baik sesi ada maupun tidak.
        $harsus = $this->fieldAccount($this->harsusTeam, 'harsus_app');
        foreach ([$this->session->id, $missing] as $id) {
            $this->api($harsus)->getJson("/api/v1/mobile/pasila/{$id}")->assertForbidden()->assertExactJson(['message' => 'Hanya akun tim POSKO yang dapat memakai PASILA.']);
        }
        $this->api()->getJson('/api/v1/mobile/pasila/bukan-uuid')->assertNotFound();
    }

    private function api(?User $account = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.($account ?? $this->account)->createToken('HP', ['field'])->plainTextToken)->withHeader('Accept', 'application/json');
    }

    protected function createApkukWorkOrder(int $apkukId): WorkOrder
    {
        $this->flushHeaders();

        return parent::createApkukWorkOrder($apkukId);
    }
}
