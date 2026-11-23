<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\AuditLog;
use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\WorkOrder;
use App\Models\WorkOrderReschedule;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B3.1 — sesi PASILA per Posko per shift dengan Section dari referensi terverifikasi.
 *
 * Referensi uji (SECTION_PIC_RAW simulasi, bukan data resmi):
 * - PUGUNG memegang LBSM GUNUNG MAS (REOG) dan LBSM BEDANA (BEDANA): satu Posko, dua penyulang.
 * - WAY JEPARA memegang REC BRAJA DEWA pada REOG: dua Posko berbeda pada penyulang yang sama.
 * - LBSM AMBIGU (dua POSKO PIC), LBSM TANPA PIC, REC KECAK (penyulang bukan master), REC MULTI
 *   (dua penyulang) ditahan. JABUNG dan SRIBHAWONO tidak memiliki Section terverifikasi.
 */
class PasilaSessionTest extends OperationsTestCase
{
    private Posko $wayJepara;

    private Posko $jabung;

    private Posko $sribhawono;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pasila.opener.enabled' => true]);
        $this->travelTo(Carbon::create(2026, 10, 29, 8, 5, 0, ShiftService::TIMEZONE));
        $this->wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        $this->jabung = Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        $this->sribhawono = Posko::create(['kode_posko' => 'SRIBHAWONO', 'nama_posko' => 'SRIBHAWONO']);
        Posko::create(['kode_posko' => 'TRIDATU', 'nama_posko' => 'TRIDATU', 'is_active' => false]);
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);
        Penyulang::create(['kode_penyulang' => 'ZAPIN', 'nama_penyulang' => 'ZAPIN']);

        $this->sectionRows([
            ['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'],
            [null, 'REC BRAJA DEWA', 'WAY JEPARA'],
            ['BEDANA', 'LBSM BEDANA', 'PUGUNG'],
            ['ZAPIN', 'LBSM AMBIGU', 'PUGUNG'],
            [null, 'LBSM AMBIGU', 'JABUNG'],
            [null, 'LBSM TANPA PIC', ''],
            ['KECAK', 'REC KECAK', 'JABUNG'],
            ['BEDANA', 'REC MULTI', 'WAY JEPARA'],
            ['ZAPIN', 'REC MULTI', 'WAY JEPARA'],
        ]);
    }

    public function test_reference_supports_many_sections_per_posko_and_many_poskos_per_penyulang(): void
    {
        $map = SectionPoskoMap::load();

        $pugung = collect($map->sectionsForPosko($this->posko->id));
        $this->assertSame(['BEDANA' => 'LBSM BEDANA', 'REOG' => 'LBSM GUNUNG MAS'], $pugung->mapWithKeys(fn ($e) => [$e['penyulang'] => $e['section']])->all());
        $this->assertSame(['REC BRAJA DEWA'], array_column($map->sectionsForPosko($this->wayJepara->id), 'section'));
        $this->assertSame('REOG', $map->entry('rec braja dewa')['penyulang'], 'Dua Posko memegang Section berbeda pada REOG.');
        $this->assertSame([], $map->sectionsForPosko($this->jabung->id));
        $this->assertSame([], $map->sectionsForPosko($this->sribhawono->id));

        $held = collect($map->entries())->whereNull('posko')->mapWithKeys(fn ($e) => [$e['section'].'@'.$e['penyulang'] => $e['reason']])->all();
        $this->assertSame([
            'REC MULTI@BEDANA' => SectionPoskoMap::REASON_SECTION_MULTI_PENYULANG,
            'REC KECAK@KECAK' => SectionPoskoMap::REASON_PENYULANG_TIDAK_DIKENAL,
            'LBSM AMBIGU@ZAPIN' => SectionPoskoMap::REASON_POSKO_AMBIGU,
            'LBSM TANPA PIC@ZAPIN' => SectionPoskoMap::REASON_POSKO_PIC_KOSONG,
            'REC MULTI@ZAPIN' => SectionPoskoMap::REASON_SECTION_MULTI_PENYULANG,
        ], $held);
        $this->assertSame(['PUGUNG', 'JABUNG'], collect($map->entries())->firstWhere('section', 'LBSM AMBIGU')['posko_pic']);

        // Berkas calon data resmi dengan isi yang sama menghasilkan pemetaan yang sama.
        $rows = DB::table('section_pic_source_rows')->orderBy('source_row_number')->get(['penyulang_raw', 'pemutus_raw', 'posko_pic_raw']);
        $flat = fn (SectionPoskoMap $m) => array_map(fn ($e) => [$e['key'], $e['penyulang'], $e['posko']?->id, $e['reason']], $m->entries());
        $this->assertSame($flat($map), $flat(SectionPoskoMap::fromRows($rows)));
    }

    public function test_opener_creates_one_session_per_active_posko_for_running_shift(): void
    {
        $result = $this->open();

        $this->assertSame('COMPLETED', $result['status']);
        $this->assertCount(4, $result['created'], 'TRIDATU nonaktif tidak dibuka.');
        $shift = ShiftInstance::query()->sole();
        $this->assertSame(['2026-10-29', 'A'], [$shift->operational_date->toDateString(), $shift->shift_code]);

        $pugung = $this->sessionOf($this->posko);
        $this->assertSame('PSL-20261029-A-PUGUNG', $pugung->code);
        $this->assertSame(PasilaSession::STATUS_DIBUKA, $pugung->status);
        $this->assertSame(PasilaSession::SECTION_REKOMENDASI, $pugung->section_status);
        // Seri rotasi (belum pernah ditugaskan) diurutkan penyulang: BEDANA sebelum REOG.
        $this->assertSame(['LBSM BEDANA', 'BEDANA'], [$pugung->section_name, $pugung->penyulang->kode_penyulang]);
        $this->assertSame(['ROTASI_SECTION', 'baseline-1', false, 2], [$pugung->recommendation['strategy'], $pugung->recommendation['version'], $pugung->recommendation['is_machine_learning'], $pugung->recommendation['considered']]);
        $this->assertSame(5, $pugung->openedLateMinutes());

        $this->assertSame('REC BRAJA DEWA', $this->sessionOf($this->wayJepara)->section_name);
        foreach ([$this->jabung, $this->sribhawono] as $posko) {
            $waiting = $this->sessionOf($posko);
            $this->assertSame(PasilaSession::SECTION_MENUNGGU, $waiting->section_status);
            $this->assertSame(PasilaSession::HOLD_TIDAK_ADA_SECTION, $waiting->section_hold_reason);
            $this->assertNull($waiting->section_key);
            $this->assertNull($waiting->penyulang_id);
        }
        $this->assertSame(4, AuditLog::query()->where('entity_type', 'pasila_session')->where('action', AuditLog::ACTION_CREATED)->count());
    }

    public function test_rerun_is_idempotent_and_only_opens_missing_poskos(): void
    {
        $this->open();
        $again = $this->open();

        $this->assertSame([[], 4], [$again['created'], $again['existing']]);
        $this->assertSame(4, PasilaSession::query()->count());
        $this->assertSame(1, ShiftInstance::query()->count());

        $tridatu = Posko::query()->where('kode_posko', 'TRIDATU')->sole();
        $tridatu->update(['is_active' => true]);
        $third = $this->open();
        $this->assertSame(['TRIDATU'], array_map(fn (PasilaSession $s) => $s->posko->nama_posko, $third['created']));
        $this->assertSame(5, PasilaSession::query()->count());
        $this->assertSame(5, AuditLog::query()->where('entity_type', 'pasila_session')->count());
    }

    public function test_rotation_moves_to_the_least_recently_assigned_owned_section(): void
    {
        $this->open();
        $this->travelTo(Carbon::create(2026, 10, 29, 16, 1, 0, ShiftService::TIMEZONE));
        $this->open();
        $this->travelTo(Carbon::create(2026, 10, 30, 0, 1, 0, ShiftService::TIMEZONE));
        $this->open();

        $sections = PasilaSession::query()->where('posko_id', $this->posko->id)->with('shiftInstance')->get()
            ->sortBy(fn (PasilaSession $s) => $s->shiftInstance->starts_at)
            ->map(fn (PasilaSession $s) => $s->shiftInstance->operational_date->toDateString().'/'.$s->shiftInstance->shift_code.'='.$s->section_name)
            ->values()->all();

        $this->assertSame(['2026-10-29/A=LBSM BEDANA', '2026-10-29/B=LBSM GUNUNG MAS', '2026-10-29/C=LBSM BEDANA'], $sections);
        $this->assertStringContainsString('terakhir ditugaskan PASILA pada Shift A hari operasional 29/10/2026', $this->sessionOf($this->posko, '2026-10-29', 'C')->recommendation['reasons'][0]);
        // WAY JEPARA hanya memiliki satu Section: tetap Section miliknya sendiri setiap shift.
        $this->assertSame(['REC BRAJA DEWA'], PasilaSession::query()->where('posko_id', $this->wayJepara->id)->pluck('section_name')->unique()->values()->all());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shiftBoundaries(): array
    {
        return [
            '00.00 milik C hari sebelumnya' => ['2026-10-30 00:00:00', '2026-10-29', 'C'],
            '07.59 masih C hari sebelumnya' => ['2026-10-30 07:59:59', '2026-10-29', 'C'],
            '08.00 awal A' => ['2026-10-30 08:00:00', '2026-10-30', 'A'],
            '15.59 masih A' => ['2026-10-30 15:59:59', '2026-10-30', 'A'],
            '16.00 awal B' => ['2026-10-30 16:00:00', '2026-10-30', 'B'],
            '23.59 masih B' => ['2026-10-30 23:59:59', '2026-10-30', 'B'],
        ];
    }

    #[DataProvider('shiftBoundaries')]
    public function test_session_belongs_to_the_shift_and_operational_date_running_at_that_moment(string $wib, string $date, string $code): void
    {
        $this->travelTo(Carbon::parse($wib, ShiftService::TIMEZONE));

        $this->open();

        $shift = ShiftInstance::query()->sole();
        $this->assertSame([$date, $code], [$shift->operational_date->toDateString(), $shift->shift_code]);
        $window = app(ShiftService::class)->window($date, $code);
        $this->assertSame($window['start']->utc()->toDateTimeString(), $shift->starts_at->toDateTimeString());
        $this->assertSame(4, $shift->pasilaSessions()->count());
    }

    public function test_late_run_records_the_delay_and_never_backfills_an_ended_shift(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 29, 15, 50, 0, ShiftService::TIMEZONE));

        // Waktu pemanggil ber-zona WIB tetap disimpan UTC (ditemukan saat uji PostgreSQL).
        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI, null, CarbonImmutable::now(ShiftService::TIMEZONE));

        $this->assertSame(470, $this->sessionOf($this->posko)->openedLateMinutes());
        $this->assertSame('2026-10-29 08:50:00', $this->sessionOf($this->posko)->opened_at->toDateTimeString());
        $this->assertSame(['2026-10-29/A'], ShiftInstance::query()->get()->map(fn ($s) => $s->operational_date->toDateString().'/'.$s->shift_code)->all(), 'Shift C hari 28 yang terlewat tidak dibuka mundur.');
    }

    public function test_opener_is_refused_while_disabled_and_scheduler_is_not_registered(): void
    {
        config(['pasila.opener.enabled' => false]);

        $result = $this->open();

        $this->assertSame('REFUSED', $result['status']);
        $this->assertStringContainsString('PASILA_OPENER_ENABLED=false', $result['message']);
        $this->assertSame(1, Artisan::call('pasila:open-shift'));
        $this->assertSame(0, PasilaSession::query()->count());
        $this->assertSame(0, ShiftInstance::query()->count());

        // routes/console.php dimuat saat boot dengan konfigurasi default (nonaktif).
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');
        $this->assertStringNotContainsString('pasila:open-shift', $commands);
    }

    public function test_command_opens_sessions_and_reports_them(): void
    {
        $this->assertSame(0, Artisan::call('pasila:open-shift', ['--trigger' => 'SCHEDULER']));
        $output = Artisan::output();

        $this->assertStringContainsString('PASILA_OPENER status=COMPLETED shift=2026-10-29/A dibuat=4 sudah_ada=0', $output);
        $this->assertStringContainsString('Menunggu penentuan Section', $output);
        $this->assertSame([PasilaSession::TRIGGER_SCHEDULER], PasilaSession::query()->pluck('opened_trigger')->unique()->values()->all());

        $this->assertSame(0, Artisan::call('pasila:open-shift'));
        $this->assertStringContainsString('dibuat=0 sudah_ada=4', Artisan::output());
    }

    public function test_session_opens_alongside_unfinished_and_rescheduled_apkuk_work_without_touching_it(): void
    {
        $assigned = $this->apkukWork('WO-DITUGASKAN', WorkOrderStatus::Ditugaskan);
        $pending = $this->apkukWork('WO-RESCHEDULE', WorkOrderStatus::RescheduleDiajukan);
        WorkOrderReschedule::create([
            'work_order_id' => $pending->id, 'reason_code' => 'CUACA', 'note' => 'Hujan deras', 'from_work_date' => '2026-10-29',
            'from_shift_code' => 'A', 'proposed_work_date' => '2026-10-29', 'proposed_shift_code' => 'B', 'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
        ]);
        $before = WorkOrder::query()->orderBy('code')->get(['id', 'status', 'work_date', 'shift_code', 'updated_at'])->toArray();

        $this->open();

        $this->assertSame(PasilaSession::STATUS_DIBUKA, $this->sessionOf($this->posko)->status);
        $this->assertSame($before, WorkOrder::query()->orderBy('code')->get(['id', 'status', 'work_date', 'shift_code', 'updated_at'])->toArray());
        $this->assertSame(WorkOrderReschedule::STATUS_MENUNGGU_ACC, WorkOrderReschedule::query()->sole()->status);
        $this->assertSame(0, DB::table('apkuk_shift_allocations')->count());
        $this->assertSame(0, DB::table('apkuk_generation_runs')->count());

        $this->actingAs($this->admin)->get(route('admin.pasila.index'))
            ->assertOk()
            ->assertSeeInOrder(['PUGUNG', 'Dibuka', 'PSL-20261029-A-PUGUNG', 'Rekomendasi sistem', 'LBSM BEDANA', '0/6', '2 WO · 2 aktif', '1 reschedule menunggu', 'Tidak ada kuota P0']);
    }

    public function test_sessions_are_still_recorded_when_no_section_reference_exists(): void
    {
        DB::table('section_pic_source_rows')->delete();

        $this->open();

        $this->assertSame(4, PasilaSession::query()->where('section_status', PasilaSession::SECTION_MENUNGGU)->count());
        $this->actingAs($this->admin)->get(route('admin.pasila.index'))
            ->assertOk()
            ->assertSee('Menunggu penentuan Section')
            ->assertSee('Posko belum memiliki Section terverifikasi pada referensi SECTION_PIC_RAW')
            ->assertSee('Belum ada Section terverifikasi; sesi PASILA Posko ini menunggu penentuan Section.');
    }

    public function test_admin_can_switch_to_another_owned_section_on_a_different_penyulang_with_audit(): void
    {
        $this->open();
        $session = $this->sessionOf($this->posko);

        $this->actingAs($this->admin)
            ->post(route('admin.pasila.section', $session), ['section' => 'lbsm gunung mas', 'reason' => 'Laporan warga ranting dekat jaringan'])
            ->assertRedirect(route('admin.pasila.show', ['posko' => $this->posko->id, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertSessionHas('flash');

        $session->refresh();
        $this->assertSame([PasilaSession::SECTION_ADMIN, 'LBSM GUNUNG MAS', 'REOG', $this->admin->id], [$session->section_status, $session->section_name, $session->penyulang->kode_penyulang, $session->section_set_by_user_id]);
        $audit = AuditLog::query()->where('entity_type', 'pasila_session')->where('action', AuditLog::ACTION_UPDATED)->sole();
        $this->assertSame(['LBSM BEDANA', 'LBSM GUNUNG MAS'], [$audit->before['section_name'], $audit->after['section_name']]);
        $this->assertSame([PasilaSession::SECTION_REKOMENDASI, PasilaSession::SECTION_ADMIN], [$audit->before['section_status'], $audit->after['section_status']]);
        $this->assertSame('Laporan warga ranting dekat jaringan', $audit->reason);
        // Rekomendasi awal sistem tetap tercatat dan tampil terpisah dari Section yang ditetapkan Admin.
        $this->assertSame(['LBSM BEDANA', 'BEDANA'], [$session->recommendation['recommended_section'], $session->recommendation['recommended_penyulang']]);
        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertSee('Rekomendasi sistem saat sesi dibuka: LBSM BEDANA (BEDANA)')
            ->assertSee('Ditetapkan Admin');
        $this->assertSame($this->admin->id, $audit->actor_user_id);
    }

    public function test_admin_cannot_assign_a_section_owned_by_another_posko_unknown_or_ambiguous(): void
    {
        $this->open();
        $session = $this->sessionOf($this->posko);
        $cases = [
            'REC BRAJA DEWA' => 'milik Posko WAY JEPARA, bukan Posko PUGUNG',
            'SECTION KARANGAN' => 'tidak ditemukan pada referensi SECTION_PIC_RAW',
            'LBSM AMBIGU' => SectionPoskoMap::LABELS[SectionPoskoMap::REASON_POSKO_AMBIGU],
            'LBSM TANPA PIC' => SectionPoskoMap::LABELS[SectionPoskoMap::REASON_POSKO_PIC_KOSONG],
            'REC MULTI' => SectionPoskoMap::LABELS[SectionPoskoMap::REASON_SECTION_MULTI_PENYULANG],
            'REC KECAK' => SectionPoskoMap::LABELS[SectionPoskoMap::REASON_PENYULANG_TIDAK_DIKENAL],
            'LBSM BEDANA' => 'sudah menjadi Section sesi ini',
        ];

        foreach ($cases as $section => $message) {
            $this->actingAs($this->admin)
                ->from(route('admin.pasila.show', $this->posko))
                ->post(route('admin.pasila.section', $session), ['section' => $section, 'reason' => 'Uji penolakan Section'])
                ->assertSessionHasErrors('section');
            $this->assertStringContainsString($message, session('errors')->first('section'), $section);
        }

        $session->refresh();
        $this->assertSame([PasilaSession::SECTION_REKOMENDASI, 'LBSM BEDANA'], [$session->section_status, $session->section_name]);
        $this->assertSame(0, AuditLog::query()->where('action', AuditLog::ACTION_UPDATED)->count());

        // Posko tanpa Section terverifikasi tidak dapat diisi Section milik Posko lain.
        $this->actingAs($this->admin)
            ->post(route('admin.pasila.section', $this->sessionOf($this->jabung)), ['section' => 'LBSM GUNUNG MAS', 'reason' => 'Uji penolakan Section'])
            ->assertSessionHasErrors('section');
        $this->assertSame(PasilaSession::SECTION_MENUNGGU, $this->sessionOf($this->jabung)->section_status);
    }

    public function test_section_change_requires_a_reason_and_is_refused_after_the_shift_ends(): void
    {
        $this->open();
        $session = $this->sessionOf($this->posko);

        $this->actingAs($this->admin)->post(route('admin.pasila.section', $session), ['section' => 'LBSM GUNUNG MAS', 'reason' => 'singkat'])
            ->assertSessionHasErrors('reason');

        $this->travelTo(Carbon::create(2026, 10, 29, 16, 0, 0, ShiftService::TIMEZONE));
        $this->actingAs($this->admin)->post(route('admin.pasila.section', $session), ['section' => 'LBSM GUNUNG MAS', 'reason' => 'Terlambat menetapkan Section'])
            ->assertSessionHasErrors('section');
        $this->assertStringContainsString('sudah berakhir', session('errors')->first('section'));
        $this->assertSame('LBSM BEDANA', $session->refresh()->section_name);
    }

    public function test_monitor_shows_sessions_references_and_separate_statuses(): void
    {
        $this->open();

        $response = $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk();

        $response->assertSee('Shift A · Pagi')->assertSee('29 Oct 2026')
            ->assertSee('ROTASI_SECTION baseline-1')
            ->assertSee('bukan ML atau skor risiko')
            ->assertSeeInOrder(['JABUNG', 'Dibuka', 'Menunggu penentuan Section', 'Posko belum memiliki Section terverifikasi'])
            ->assertSeeInOrder(['WAY JEPARA', 'PSL-20261029-A-WAYJEPARA', 'Rekomendasi sistem', 'REC BRAJA DEWA', 'REOG'])
            ->assertSee('Dibuka 5 menit setelah awal shift')
            ->assertSee('data-reference-posko="PUGUNG"', false)
            ->assertSeeInOrder(['data-reference-posko="PUGUNG"', '2', 'BEDANA, REOG'], false)
            ->assertSeeInOrder(['Referensi ditahan (5)', 'LBSM AMBIGU', 'PUGUNG, JABUNG', SectionPoskoMap::LABELS[SectionPoskoMap::REASON_POSKO_AMBIGU]])
            ->assertSee('Buka sesi shift berjalan');
        $this->assertSame(['sessions' => 4, 'waiting' => 2], array_intersect_key($response->viewData('totals'), ['sessions' => 0, 'waiting' => 0]));

        // Shift lain tanpa sesi: jujur, tidak dibuat mundur.
        $this->actingAs($this->admin)->get(route('admin.pasila.index', ['work_date' => '2026-10-28', 'shift_code' => 'B']))
            ->assertOk()
            ->assertSee('Tidak ada sesi')
            ->assertSee('Shift sudah berakhir; sesi tidak dibuat mundur.')
            ->assertSee('Hanya shift berjalan yang dapat dibuka.')
	    ->assertSee('aria-disabled="true"', false);
    }

    public function test_detail_shows_session_owned_sections_and_assignment_form(): void
    {
        $this->open();

        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()
            ->assertSee('data-session="PSL-20261029-A-PUGUNG"', false)
            ->assertSee('Rotasi: Section ini belum pernah ditugaskan PASILA untuk Posko PUGUNG.')
            ->assertSee('data-owned-section="LBSM BEDANA"', false)
            ->assertSee('data-owned-section="LBSM GUNUNG MAS"', false)
            ->assertDontSee('data-owned-section="REC BRAJA DEWA"', false)
            ->assertSee(route('admin.pasila.section', $this->sessionOf($this->posko)))
            ->assertSee('LBSM GUNUNG MAS — REOG')
            ->assertSee('Buat P0 dari PASILA');

        $this->actingAs($this->admin)->get(route('admin.pasila.show', ['posko' => $this->jabung, 'work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertOk()
            ->assertSee('data-hold-reason', false)
            ->assertSee('Posko ini belum memiliki Section terverifikasi.');
    }

    public function test_admin_web_open_uses_the_same_opener_and_is_refused_when_disabled(): void
    {
        $this->actingAs($this->admin)->post(route('admin.pasila.open'))
            ->assertRedirect(route('admin.pasila.index', ['work_date' => '2026-10-29', 'shift_code' => 'A']))
            ->assertSessionHas('flash', '4 sesi PASILA dibuka untuk shift berjalan; 0 sesi sudah ada sebelumnya.');
        $this->assertSame([PasilaSession::TRIGGER_ADMIN_WEB], PasilaSession::query()->pluck('opened_trigger')->unique()->values()->all());
        $this->assertSame($this->admin->id, $this->sessionOf($this->posko)->opened_by_user_id);

        config(['pasila.opener.enabled' => false]);
        $this->actingAs($this->admin)->post(route('admin.pasila.open'))->assertSessionHasErrors('opener');
        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertSee('PASILA_OPENER_ENABLED=false');
    }

    public function test_guests_and_field_accounts_cannot_open_sessions_or_change_sections(): void
    {
        $this->open();
        $session = $this->sessionOf($this->posko);
        $field = $this->fieldAccount($this->poskoTeam, 'POSKO_PUGUNG_APP');

        $this->post(route('admin.pasila.open'))->assertRedirect(route('login'));
        $this->post(route('admin.pasila.section', $session), ['section' => 'LBSM GUNUNG MAS', 'reason' => 'Akses tanpa login'])->assertRedirect(route('login'));
        $this->actingAs($field)->get(route('admin.pasila.index'))->assertForbidden();
        $this->actingAs($field)->get(route('admin.pasila.show', $this->posko))->assertForbidden();
        $this->actingAs($field)->post(route('admin.pasila.open'))->assertForbidden();
        $this->actingAs($field)->post(route('admin.pasila.section', $session), ['section' => 'LBSM GUNUNG MAS', 'reason' => 'Akses akun lapangan'])->assertForbidden();

        $this->assertSame('LBSM BEDANA', $session->refresh()->section_name);
        $this->assertSame(4, PasilaSession::query()->count());
    }

    public function test_reference_command_reports_staging_and_validates_a_candidate_file_without_writing(): void
    {
        $this->assertSame(0, Artisan::call('pasila:section-references'));
        $output = Artisan::output();
        $this->assertStringContainsString('SECTION_REFERENCES section=8 terverifikasi=3 ditahan=5 posko_tanpa_section=2', $output);
        $this->assertStringContainsString('Posko aktif tanpa Section terverifikasi: JABUNG, SRIBHAWONO', $output);
        $this->assertStringContainsString(SectionPoskoMap::REASON_POSKO_AMBIGU, $output);

        // Calon data resmi (delimiter ;, baris kosong sebelum header, fill-down PENYULANG): JABUNG kini terpetakan.
        $file = tempnam(sys_get_temp_dir(), 'sec').'.csv';
        file_put_contents($file, "\xEF\xBB\xBF;;\nPENYULANG;PEMUTUS;POSKO PIC\nZAPIN;LBSM AMBIGU;JABUNG\n;LBSM JABUNG 2;JABUNG\nREOG;LBSM GUNUNG MAS;PUGUNG\n;;\n");
        $this->assertSame(0, Artisan::call('pasila:section-references', ['--file' => $file]));
        $output = Artisan::output();
        $this->assertStringContainsString('3 baris data; tidak diimport', $output);
        $this->assertStringContainsString('SECTION_REFERENCES section=3 terverifikasi=3 ditahan=0 posko_tanpa_section=2', $output);
        $this->assertStringContainsString('Posko aktif tanpa Section terverifikasi: SRIBHAWONO, WAY JEPARA', $output);

        foreach (["PEMUTUS,PENYULANG,POSKO PIC\nLBSM X,REOG,PUGUNG\n", "Penyulang,Pemutus,Posko PIC\nREOG,LBSM X,PUGUNG\n", "PENYULANG,PEMUTUS,POSKO PIC,CATATAN\nREOG,LBSM X,PUGUNG,-\n"] as $invalid) {
            file_put_contents($file, $invalid);
            $this->assertSame(1, Artisan::call('pasila:section-references', ['--file' => $file]), 'Urutan salah, huruf kecil, atau kolom tambahan ditolak seperti importer.');
            $this->assertStringContainsString('Header tidak sesuai kontrak', Artisan::output());
        }
        @unlink($file);

        $this->assertSame(9, DB::table('section_pic_source_rows')->count(), 'Validasi berkas tidak menulis staging.');
    }

    public function test_source_import_rejects_an_unknown_only_sheet_before_reading_google(): void
    {
        $this->assertSame(1, Artisan::call('sources:import', ['--only' => ['SECTION_PIC']]));
        $this->assertStringContainsString('Sheet tidak dikenal untuk --only: SECTION_PIC. Pilihan: ASSET_RAW, VEGETASI_RAW, SECTION_PIC_RAW.', Artisan::output());
    }

    /**
     * @return array{status: string, message: ?string, shift: ?ShiftInstance, created: array<int, PasilaSession>, existing: int}
     */
    private function open(): array
    {
        return app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
    }

    private function sessionOf(Posko $posko, string $date = '2026-10-29', string $code = 'A'): PasilaSession
    {
        return PasilaSession::query()
            ->where('posko_id', $posko->id)
            ->whereHas('shiftInstance', fn ($q) => $q->whereDate('operational_date', $date)->where('shift_code', $code))
            ->with(['penyulang', 'shiftInstance'])
            ->sole();
    }

    /**
     * @param  array<int, array{?string, string, string}>  $rows
     */
    private function sectionRows(array $rows): void
    {
        foreach ($rows as $i => [$penyulang, $pemutus, $posko]) {
            DB::table('section_pic_source_rows')->insert([
                'penyulang_raw' => $penyulang, 'pemutus_raw' => $pemutus, 'posko_pic_raw' => $posko === '' ? null : $posko,
                'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function apkukWork(string $code, WorkOrderStatus $status): WorkOrder
    {
        return tap((new WorkOrder)->forceFill([
            'code' => $code,
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => $status,
            'posko_id' => $this->posko->id,
            'work_date' => '2026-10-29',
            'shift_code' => 'A',
        ]))->save();
    }
}
