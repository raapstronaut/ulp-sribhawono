<?php

namespace Tests\Feature\Operations;

use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\PasilaFinding;
use App\Models\PasilaFindingMedia;
use App\Models\PasilaNilReport;
use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Services\Admin\MasterDataService;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * B3.2 — temuan PASILA per titik dan laporan nihil dari aplikasi POSKO, ditampilkan di Web Admin.
 *
 * Waktu uji: 29 Okt 2026 09:00 WIB (Shift A hari operasional 29: 08.00–16.00 WIB).
 * Referensi Section simulasi: PUGUNG memegang LBSM BEDANA (BEDANA) dan LBSM GUNUNG MAS (REOG);
 * WAY JEPARA memegang REC BRAJA DEWA (REOG); LBSM AMBIGU ditahan (dua POSKO PIC); JABUNG tanpa Section.
 */
class PasilaFindingTest extends OperationsTestCase
{
    private Posko $wayJepara;

    private Posko $jabung;

    private FieldTeam $wayJeparaTeam;

    private FieldTeam $jabungTeam;

    private User $pugungAccount;

    private PasilaSession $pugungSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 29, 9, 0, 0, ShiftService::TIMEZONE));
        config(['pasila.opener.enabled' => true]);
        $this->wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        $this->jabung = Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);
        $this->wayJeparaTeam = FieldTeam::create(['code' => 'POSKO-WJ', 'name' => 'Tim Way Jepara', 'role' => 'POSKO', 'posko_id' => $this->wayJepara->id]);
        $this->wayJeparaTeam->personnel()->create(['name' => 'Wati Jepara']);
        $this->jabungTeam = FieldTeam::create(['code' => 'POSKO-JB', 'name' => 'Tim Jabung', 'role' => 'POSKO', 'posko_id' => $this->jabung->id]);
        $this->jabungTeam->personnel()->create(['name' => 'Joni Jabung']);
        $this->poskoTeam->personnel()->create(['name' => 'Ani Posko']);

        foreach ([['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], [null, 'REC BRAJA DEWA', 'WAY JEPARA'], ['BEDANA', 'LBSM BEDANA', 'PUGUNG'], [null, 'LBSM AMBIGU', 'PUGUNG'], [null, 'LBSM AMBIGU', 'JABUNG']] as $i => [$p, $s, $k]) {
            DB::table('section_pic_source_rows')->insert(['penyulang_raw' => $p, 'pemutus_raw' => $s, 'posko_pic_raw' => $k, 'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM', 'role_scope' => 'POSKO']);
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'ISOLATOR_RETAK', 'label' => 'Isolator retak']);
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'KODE_LAMA', 'label' => 'Kode nonaktif', 'is_active' => false]);

        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
        $this->pugungSession = PasilaSession::query()->where('posko_id', $this->posko->id)->sole();
        $this->pugungAccount = $this->fieldAccount($this->poskoTeam, 'posko_pugung_app');
    }

    public function test_posko_records_many_findings_each_with_its_own_evidence(): void
    {
        $this->assertSame('LBSM BEDANA', $this->pugungSession->section_name);
        $ani = $this->poskoTeam->personnel()->where('name', 'Ani Posko')->sole();

        $first = $this->finding($this->pugungAccount, ['client_request_id' => 'pasila-1'])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.section', 'LBSM BEDANA')
            ->assertJsonPath('data.photo_count', 2)
            ->assertJsonPath('meta.idempotent_replay', false);
        // Temuan kedua di Section milik Posko yang sama pada penyulang lain, dengan GPS, waktu, foto, dan petugas sendiri.
        $this->finding($this->pugungAccount, [
            'client_request_id' => 'pasila-2', 'section' => 'lbsm gunung mas', 'finding_code' => 'isolator_retak',
            'description' => 'Isolator tiang retak rambut', 'latitude' => -5.31, 'longitude' => 105.81, 'gps_accuracy_m' => 4.5,
            'occurred_at' => '2026-10-29T08:55:00+07:00', 'personnel_ids' => [$ani->id], 'photos' => [$this->photo('isolator.jpg')],
        ])->assertCreated()->assertJsonPath('data.sequence', 2)->assertJsonPath('data.section', 'LBSM GUNUNG MAS');

        $findings = PasilaFinding::query()->with(['media', 'personnel', 'penyulang'])->orderBy('sequence')->get();
        $this->assertCount(2, $findings);
        [$a, $b] = [$findings[0], $findings[1]];
        $this->assertSame([$this->posko->id, $this->pugungSession->id, 'BEDANA', 'POHON_DEKAT_JTM', PasilaFinding::STATUS_TERCATAT], [$a->posko_id, $a->pasila_session_id, $a->penyulang->kode_penyulang, $a->finding_code, $a->status]);
        $this->assertSame(['REOG', 'LBSM GUNUNG MAS', '-5.3100000', '105.8100000'], [$b->penyulang->kode_penyulang, $b->section_name, $b->latitude, $b->longitude]);
        $this->assertSame('2026-10-29 01:55:00', $b->occurred_at->toDateTimeString(), 'Waktu kejadian disimpan UTC.');
        $this->assertSame([[$this->poskoPerson->id], [$ani->id]], [$a->personnel->pluck('field_personnel_id')->all(), $b->personnel->pluck('field_personnel_id')->all()]);
        $this->assertSame([2, 1], [$a->media->count(), $b->media->count()]);
        $this->assertSame([$this->pugungAccount->id, $this->poskoTeam->id, PasilaFinding::COORDINATE_DEVICE], [$a->submitted_by_user_id, $a->submitted_by_field_team_id, $a->coordinate_source]);
        foreach (PasilaFindingMedia::all() as $photo) {
            Storage::disk('local')->assertExists($photo->storage_path);
            $this->assertNotNull($photo->sha256);
        }
        $this->assertSame(2, AuditLog::query()->where('entity_type', 'pasila_finding')->where('action', AuditLog::ACTION_CREATED)->count());
        $this->assertSame(PasilaSession::PATROL_ADA_TEMUAN, $this->pugungSession->refresh()->patrolStatus());

        // PASILA tidak menyentuh WO APKUK, kuota, maupun P0.
        $this->assertSame([0, 0], [DB::table('work_orders')->count(), DB::table('apkuk_shift_allocations')->count()]);

        $this->api($this->pugungAccount)->getJson("/api/v1/mobile/pasila/{$this->pugungSession->id}")
            ->assertOk()
            ->assertJsonPath('data.patrol_status', PasilaSession::PATROL_ADA_TEMUAN)
            ->assertJsonPath('data.finding_count', 2)
            ->assertJsonPath('data.findings.1.personnel.0.name', 'Ani Posko')
            ->assertJsonPath('data.sections', [['section' => 'LBSM BEDANA', 'penyulang' => 'BEDANA'], ['section' => 'LBSM GUNUNG MAS', 'penyulang' => 'REOG']])
            ->assertJsonPath('data.finding_codes', [['code' => 'ISOLATOR_RETAK', 'label' => 'Isolator retak'], ['code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM']]);
        $first->assertJsonPath('data.personnel.0.name', 'Budi Posko');
    }

    public function test_retry_with_the_same_client_request_id_returns_the_existing_finding(): void
    {
        $photos = [$this->photo('a.jpg')];
        $payload = ['client_request_id' => 'pasila-retry', 'photos' => $photos];

        $this->finding($this->pugungAccount, $payload)->assertCreated();
        $files = count(Storage::disk('local')->allFiles());
        $this->finding($this->pugungAccount, $payload)->assertCreated()->assertJsonPath('meta.idempotent_replay', true)->assertJsonPath('data.sequence', 1);

        $this->assertSame(1, PasilaFinding::query()->count());
        $this->assertSame($files, count(Storage::disk('local')->allFiles()), 'Replay tidak menyimpan foto baru.');

        $this->finding($this->pugungAccount, ['client_request_id' => 'pasila-retry', 'description' => 'Isi lain dengan kunci sama', 'photos' => $photos])
            ->assertUnprocessable()->assertJsonValidationErrors('client_request_id');
        $this->assertSame(1, PasilaFinding::query()->count());
    }

    public function test_other_posko_and_other_roles_cannot_report_or_read_the_session(): void
    {
        $wayJeparaAccount = $this->fieldAccount($this->wayJeparaTeam, 'posko_wj_app');
        $harsus = $this->fieldAccount($this->harsusTeam, 'harsus_app');
        $inspeksi = $this->fieldAccount($this->inspeksiTeam, 'inspeksi_app');
        $url = "/api/v1/mobile/pasila/{$this->pugungSession->id}";

        $this->postJson("{$url}/findings")->assertUnauthorized();
        $this->finding($wayJeparaAccount, ['personnel_ids' => [$this->wayJeparaTeam->personnel()->value('id')]])->assertNotFound();
        $this->api($wayJeparaAccount)->getJson($url)->assertNotFound();
        $this->finding($harsus)->assertForbidden();
        $this->api($inspeksi)->getJson('/api/v1/mobile/pasila')->assertForbidden();
        $this->nil($wayJeparaAccount, ['personnel_ids' => [$this->wayJeparaTeam->personnel()->value('id')]])->assertNotFound();
        $this->assertSame([0, 0], [PasilaFinding::query()->count(), PasilaNilReport::query()->count()]);

        $codes = collect($this->api($wayJeparaAccount)->getJson('/api/v1/mobile/pasila')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['PSL-20261029-A-WAYJEPARA'], $codes, 'Tim hanya melihat sesi Posko-nya sendiri.');
    }

    public function test_finding_section_must_be_verified_and_owned_by_the_session_posko(): void
    {
        $cases = [
            'REC BRAJA DEWA' => 'milik Posko WAY JEPARA, bukan Posko sesi ini',
            'SECTION KARANGAN' => 'tidak ditemukan pada referensi SECTION_PIC_RAW',
            'LBSM AMBIGU' => 'ditahan',
        ];
        foreach ($cases as $section => $message) {
            $response = $this->finding($this->pugungAccount, ['client_request_id' => 'sec-'.md5($section), 'section' => $section])->assertUnprocessable();
            $this->assertStringContainsString($message, $response->json('errors.section.0'), $section);
        }

        // Sesi tanpa Section terverifikasi (JABUNG): temuan tidak bisa dicatat tanpa Section sah.
        $jabungAccount = $this->fieldAccount($this->jabungTeam, 'posko_jb_app');
        $jabungSession = PasilaSession::query()->where('posko_id', $this->jabung->id)->sole();
        $response = $this->finding($jabungAccount, ['personnel_ids' => [$this->jabungTeam->personnel()->value('id')]], $jabungSession)->assertUnprocessable();
        $this->assertStringContainsString('Sesi belum memiliki Section terverifikasi', $response->json('errors.section.0'));
        $this->assertSame(0, PasilaFinding::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Foto penolakan tidak tertinggal di disk.');
    }

    public function test_gps_photo_time_code_and_reporter_are_validated(): void
    {
        $other = $this->wayJeparaTeam->personnel()->value('id');
        $keypointOtherFeeder = $this->keypoint(['penyulang' => 'ZAPIN']);
        $cases = [
            'tanpa foto' => [['photos' => []], 'photos'],
            'GPS 0,0' => [['latitude' => 0, 'longitude' => 0], 'latitude'],
            'lintang di luar rentang' => [['latitude' => 95], 'latitude'],
            'tanpa GPS' => [['latitude' => null], 'latitude'],
            'sebelum shift' => [['occurred_at' => '2026-10-29T07:59:00+07:00'], 'occurred_at'],
            'masa depan' => [['occurred_at' => '2026-10-29T09:30:00+07:00'], 'occurred_at'],
            'kode nonaktif' => [['finding_code' => 'KODE_LAMA'], 'finding_code'],
            'kode tidak dikenal' => [['finding_code' => 'KARANGAN'], 'finding_code'],
            'petugas tim lain' => [['personnel_ids' => [$other]], 'personnel_ids'],
            'nama petugas ketik bebas saja' => [['personnel_ids' => [], 'personnel_names' => ['Orang Lain']], 'personnel_ids'],
            'keypoint penyulang lain' => [['keypoint_id' => $keypointOtherFeeder], 'keypoint_id'],
            'uraian kosong' => [['description' => ''], 'description'],
        ];
        foreach ($cases as $label => [$override, $field]) {
            $this->finding($this->pugungAccount, ['client_request_id' => 'val-'.md5($label)] + $override)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        ReferenceCode::query()->where('category', ReferenceCode::CATEGORY_PASILA_FINDING)->update(['is_active' => false]);
        $response = $this->finding($this->pugungAccount, ['client_request_id' => 'val-no-codes'])->assertUnprocessable();
        $this->assertStringContainsString('Admin perlu mengisi Master Data kategori PASILA_FINDING', $response->json('errors.finding_code.0'));

        $this->assertSame(0, PasilaFinding::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_offline_finding_synced_after_the_shift_is_accepted_when_it_occurred_inside_the_shift(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 29, 16, 40, 0, ShiftService::TIMEZONE));

        $this->finding($this->pugungAccount, ['client_request_id' => 'late-1', 'occurred_at' => '2026-10-29T15:50:00+07:00'])->assertCreated();
        $this->finding($this->pugungAccount, ['client_request_id' => 'late-2', 'occurred_at' => '2026-10-29T16:05:00+07:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

        $finding = PasilaFinding::query()->sole();
        $this->assertTrue($finding->received_at->gt($this->pugungSession->shiftInstance->ends_at));
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()->assertSee('(terkirim setelah shift berakhir)');
    }

    public function test_nil_report_is_explicit_and_never_inferred_from_an_empty_list(): void
    {
        $url = "/api/v1/mobile/pasila/{$this->pugungSession->id}";
        $this->api($this->pugungAccount)->getJson($url)->assertOk()
            ->assertJsonPath('data.patrol_status', PasilaSession::PATROL_BELUM)
            ->assertJsonPath('data.findings', [])
            ->assertJsonPath('data.nil_report', null);
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()->assertSee('Belum ada laporan patroli.');

        $this->nil($this->pugungAccount, ['client_request_id' => 'nil-short', 'note' => 'aman'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->nil($this->pugungAccount)->assertCreated()->assertJsonPath('data.status', PasilaNilReport::STATUS_AKTIF);
        $this->nil($this->pugungAccount)->assertCreated()->assertJsonPath('meta.idempotent_replay', true);
        $this->nil($this->pugungAccount, ['client_request_id' => 'nil-2'])->assertStatus(409);

        $this->assertSame(PasilaSession::PATROL_NIHIL, $this->pugungSession->refresh()->patrolStatus());
        $this->assertSame([1, 0], [PasilaNilReport::query()->count(), PasilaFinding::query()->count()], 'Laporan nihil tidak membuat temuan (dan tidak ada P0).');
        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk()
            ->assertSee('data-patrol-status="NIHIL"', false)->assertSee('Nihil (dilaporkan petugas)');
    }

    public function test_nil_report_is_refused_once_findings_exist_and_a_later_finding_supersedes_it(): void
    {
        // Nihil lebih dulu, lalu temuan (mis. kiriman offline yang terlambat): bukti lapangan menang, riwayat nihil tetap.
        $this->nil($this->pugungAccount)->assertCreated();
        $this->finding($this->pugungAccount)->assertCreated();

        $nil = PasilaNilReport::query()->sole();
        $finding = PasilaFinding::query()->sole();
        $this->assertSame([PasilaNilReport::STATUS_DIGANTIKAN, $finding->id], [$nil->status, $nil->superseded_by_finding_id]);
        $this->assertSame(PasilaSession::PATROL_ADA_TEMUAN, $this->pugungSession->refresh()->patrolStatus());
        $this->assertSame(1, AuditLog::query()->where('entity_type', 'pasila_nil_report')->where('action', AuditLog::ACTION_UPDATED)->count());
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()->assertSee('Digantikan temuan #1');

        // Sesi lain yang sudah punya temuan menolak laporan nihil.
        $wayJeparaAccount = $this->fieldAccount($this->wayJeparaTeam, 'posko_wj_app');
        $wj = PasilaSession::query()->where('posko_id', $this->wayJepara->id)->sole();
        $wjPerson = $this->wayJeparaTeam->personnel()->value('id');
        $this->finding($wayJeparaAccount, ['client_request_id' => 'wj-1', 'personnel_ids' => [$wjPerson]], $wj)->assertCreated();
        $this->nil($wayJeparaAccount, ['client_request_id' => 'wj-nil', 'personnel_ids' => [$wjPerson]], $wj)
            ->assertStatus(409)->assertJsonPath('message', 'Sesi PSL-20261029-A-WAYJEPARA sudah memiliki 1 temuan; laporan nihil tidak dapat dibuat.');
    }

    public function test_section_reference_change_applies_to_new_findings_and_old_findings_keep_their_record(): void
    {
        $this->finding($this->pugungAccount, ['client_request_id' => 'ref-1', 'section' => 'LBSM GUNUNG MAS'])->assertCreated();

        // Referensi resmi kemudian menyerahkan LBSM GUNUNG MAS ke WAY JEPARA.
        DB::table('section_pic_source_rows')->where('pemutus_raw', 'LBSM GUNUNG MAS')->update(['posko_pic_raw' => 'WAY JEPARA']);

        $response = $this->finding($this->pugungAccount, ['client_request_id' => 'ref-2', 'section' => 'LBSM GUNUNG MAS'])->assertUnprocessable();
        $this->assertStringContainsString('milik Posko WAY JEPARA', $response->json('errors.section.0'));
        $this->finding($this->pugungAccount, ['client_request_id' => 'ref-3'])->assertCreated()->assertJsonPath('data.section', 'LBSM BEDANA');

        $this->assertSame('LBSM GUNUNG MAS', PasilaFinding::query()->where('sequence', 1)->value('section_name'), 'Temuan lama tetap tercatat apa adanya.');
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()->assertSeeInOrder(['#1', 'LBSM GUNUNG MAS', 'Referensi terkini mencatat Section ini milik Posko WAY JEPARA.', '#2']);
    }

    public function test_admin_sees_real_findings_with_location_evidence_time_status_and_reporter(): void
    {
        $this->finding($this->pugungAccount)->assertCreated();
        $photo = PasilaFindingMedia::query()->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk()
            ->assertSee('data-patrol-status="ADA_TEMUAN"', false)->assertSee('1 temuan');
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()
            ->assertSee('data-finding="1"', false)
            ->assertSee('POHON_DEKAT_JTM')
            ->assertSee('Pohon dekat JTM')
            ->assertSee('Ranting bambu menyentuh JTM')
            ->assertSee('LBSM BEDANA (BEDANA)')
            ->assertSee('-5.200100, 105.700100')
            ->assertSee('29/10/2026 08:45 WIB')
            ->assertSee('Tercatat')
            ->assertSee('Budi Posko')
            ->assertSee('POSKO-PUGUNG-A (posko_pugung_app)')
            ->assertSee(route('admin.media.pasila', $photo));
        $this->actingAs($this->admin)->get(route('admin.media.pasila', $photo))->assertOk();
        // Foto hanya untuk Admin; akun lapangan ditolak (tamu ditolak middleware grup /admin, AdminAuthorizationTest).
        $this->actingAs($this->pugungAccount)->get(route('admin.media.pasila', $photo))->assertForbidden();
    }

    public function test_bootstrap_lists_finding_codes_and_used_codes_and_personnel_cannot_be_deleted(): void
    {
        $this->api($this->pugungAccount)->getJson('/api/v1/mobile/bootstrap')->assertOk()
            ->assertJsonPath('data.reference_codes.PASILA_FINDING', [['code' => 'ISOLATOR_RETAK', 'label' => 'Isolator retak'], ['code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM']]);

        $this->finding($this->pugungAccount)->assertCreated();
        $master = app(MasterDataService::class);
        $code = ReferenceCode::query()->where('code', 'POHON_DEKAT_JTM')->sole();
        $this->assertTrue($master->codeInUse($code));
        $this->assertTrue($master->personnelInUse($this->poskoPerson));
        $this->assertTrue($master->teamInUse($this->poskoTeam));
        $this->expectException(ValidationException::class);
        $master->deleteReferenceCode($this->admin, $code, 'Uji kode dipakai temuan');
    }

    private function api(User $account): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$account->createToken('HP', ['field'])->plainTextToken)->withHeader('Accept', 'application/json');
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function finding(User $account, array $override = [], ?PasilaSession $session = null): TestResponse
    {
        $payload = array_merge([
            'client_request_id' => 'pasila-default',
            'finding_code' => 'POHON_DEKAT_JTM',
            'description' => 'Ranting bambu menyentuh JTM',
            'latitude' => -5.2001,
            'longitude' => 105.7001,
            'occurred_at' => '2026-10-29T08:45:00+07:00',
            'personnel_ids' => [$this->poskoPerson->id],
            'photos' => [$this->photo('sebelum.jpg'), $this->photo('dekat.jpg')],
        ], $override);
        $payload = array_filter($payload, fn ($value) => $value !== null);

        return $this->api($account)->post('/api/v1/mobile/pasila/'.($session ?? $this->pugungSession)->id.'/findings', $payload);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function nil(User $account, array $override = [], ?PasilaSession $session = null): TestResponse
    {
        return $this->api($account)->postJson('/api/v1/mobile/pasila/'.($session ?? $this->pugungSession)->id.'/nil-report', array_merge([
            'client_request_id' => 'nil-1',
            'note' => 'Patroli LBSM BEDANA sampai ujung jaringan, kondisi aman',
            'latitude' => -5.2101,
            'longitude' => 105.7101,
            'occurred_at' => '2026-10-29T08:50:00+07:00',
            'personnel_ids' => [$this->poskoPerson->id],
        ], $override));
    }
}
