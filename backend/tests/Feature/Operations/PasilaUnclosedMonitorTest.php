<?php

namespace Tests\Feature\Operations;

use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\PasilaClosure;
use App\Models\PasilaSession;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Services\Pasila\PasilaShiftOpener;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * B3.5 — pemantauan sesi PASILA yang sudah melewati akhir shift tetapi belum ditutup. Data simulasi.
 *
 * Urutan waktu: Shift C hari operasional 27 Okt dibuka 28 Okt 02.00 WIB (berakhir 28 Okt 08.00),
 * Shift A 29 Okt dibuka 09.00 (berakhir 16.00), lalu Shift B 29 Okt dibuka 19.30 WIB (sekarang).
 */
class PasilaUnclosedMonitorTest extends OperationsTestCase
{
    private FieldTeam $wayJeparaTeam;

    private Posko $wayJepara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pasila.opener.enabled' => true]);
        $this->wayJepara = Posko::create(['kode_posko' => 'WAY JEPARA', 'nama_posko' => 'WAY JEPARA']);
        $this->wayJeparaTeam = FieldTeam::create(['code' => 'POSKO-WJ', 'name' => 'Tim Way Jepara', 'role' => 'POSKO', 'posko_id' => $this->wayJepara->id]);
        $this->wayJeparaTeam->personnel()->create(['name' => 'Wati Jepara']);
        foreach ([['REOG', 'LBSM GUNUNG MAS', 'PUGUNG'], ['REOG', 'REC BRAJA DEWA', 'WAY JEPARA']] as $i => [$p, $s, $k]) {
            DB::table('section_pic_source_rows')->insert(['penyulang_raw' => $p, 'pemutus_raw' => $s, 'posko_pic_raw' => $k, 'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReferenceCode::create(['category' => ReferenceCode::CATEGORY_PASILA_FINDING, 'code' => 'POHON_DEKAT_JTM', 'label' => 'Pohon dekat JTM']);

        $this->openAt(28, 2, 0);
        $this->openAt(29, 9, 0);

        // Way Jepara menutup Shift A tepat waktu; Pugung belum menutup apa pun.
        $this->travelTo(Carbon::create(2026, 10, 29, 10, 0, 0, ShiftService::TIMEZONE));
        $wj = $this->sessionOf('20261029-A-WAYJEPARA');
        $account = $this->fieldAccount($this->wayJeparaTeam, 'posko_wj_app');
        $person = $this->wayJeparaTeam->personnel()->value('id');
        $this->api($account)->postJson("/api/v1/mobile/pasila/{$wj->id}/nil-report", [
            'client_request_id' => 'wj-nil', 'note' => 'Patroli REC Braja Dewa sampai ujung, aman', 'latitude' => -5.2, 'longitude' => 105.7,
            'occurred_at' => '2026-10-29T09:50:00+07:00', 'personnel_ids' => [$person],
        ])->assertCreated();
        $this->api($account)->postJson("/api/v1/mobile/pasila/{$wj->id}/close", [
            'client_request_id' => 'wj-close', 'occurred_at' => '2026-10-29T09:55:00+07:00', 'personnel_ids' => [$person],
        ])->assertCreated();

        $this->openAt(29, 19, 30);
        $this->flushHeaders();
    }

    public function test_sessions_past_shift_end_without_closure_are_listed_with_lateness_and_keep_their_status(): void
    {
        $this->assertSame(
            ['PSL-20261027-C-PUGUNG', 'PSL-20261027-C-WAYJEPARA', 'PSL-20261029-A-PUGUNG'],
            PasilaSession::query()->unclosedAfterShift()->orderBy('code')->pluck('code')->all(),
            'Sesi yang sudah ditutup dan sesi shift berjalan tidak termasuk.',
        );
        $late = $this->sessionOf('20261029-A-PUGUNG');
        $this->assertSame([210, 'Belum ditutup 3 jam 30 menit setelah shift berakhir'], [$late->minutesUnclosedAfterShift(), $late->unclosedAfterShiftLabel()]);
        $this->assertNull($this->sessionOf('20261029-A-WAYJEPARA')->minutesUnclosedAfterShift(), 'Sesi ditutup tidak terlambat.');
        $this->assertNull($this->sessionOf('20261029-B-PUGUNG')->minutesUnclosedAfterShift(), 'Shift berjalan belum terlambat.');

        $before = PasilaSession::query()->orderBy('code')->get(['code', 'status', 'updated_at'])->toArray();
        $response = $this->actingAs($this->admin)->get(route('admin.pasila.index', ['work_date' => '2026-10-29', 'shift_code' => 'A']))->assertOk()
            ->assertSee('Belum ditutup setelah shift berakhir (3)')
            ->assertSee('data-unclosed-panel="3"', false)
            ->assertSeeInOrder(['data-unclosed-session="PSL-20261027-C-PUGUNG" data-unclosed-minutes="2130"', '1 hari 11 jam', 'data-unclosed-session="PSL-20261029-A-PUGUNG" data-unclosed-minutes="210"', '3 jam 30 menit'], false)
            ->assertDontSee('data-unclosed-session="PSL-20261029-A-WAYJEPARA"', false)
            ->assertDontSee('data-unclosed-session="PSL-20261029-B-PUGUNG"', false)
            // Baris shift A: status asli tetap "Dibuka", keterlambatan ditampilkan terpisah.
            ->assertSeeInOrder(['data-posko="PUGUNG"', 'data-session-status="DIBUKA"', 'Dibuka', 'Belum ditutup 3 jam 30 menit setelah shift berakhir'], false);
        $this->assertSame(3, $response->viewData('unclosed')['total']);

        $this->get(route('admin.pasila.index', ['posko_id' => $this->wayJepara->id]))->assertOk()
            ->assertSee('data-unclosed-panel="1"', false)->assertSee('data-unclosed-session="PSL-20261027-C-WAYJEPARA"', false)
            ->assertDontSee('data-unclosed-session="PSL-20261029-A-PUGUNG"', false);

        $this->get(route('admin.pasila.show', ['posko' => $this->posko, 'work_date' => '2026-10-29', 'shift_code' => 'A']))->assertOk()
            ->assertSee('data-unclosed-minutes="210"', false)
            ->assertSee('Belum ditutup 3 jam 30 menit setelah shift berakhir')
            ->assertSee('Status tetap Dibuka dan sistem tidak menutup otomatis.');
        $this->get(route('admin.pasila.show', ['posko' => $this->wayJepara, 'work_date' => '2026-10-29', 'shift_code' => 'A']))->assertOk()
            ->assertDontSee('data-unclosed-label', false)->assertSee('Sesi ditutup · Ditutup nihil');

        // Pemantauan dan pembukaan shift berikutnya tidak menutup atau mengubah sesi lama.
        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
        $this->assertSame($before, PasilaSession::query()->orderBy('code')->get(['code', 'status', 'updated_at'])->toArray());
        $this->assertSame(1, PasilaClosure::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACTION_CLOSED)->count());
    }

    public function test_late_closure_from_an_offline_phone_is_still_accepted_and_leaves_the_list(): void
    {
        $session = $this->sessionOf('20261029-A-PUGUNG');
        $account = $this->fieldAccount($this->poskoTeam, 'posko_pugung_app');
        $this->api($account)->postJson("/api/v1/mobile/pasila/{$session->id}/nil-report", [
            'client_request_id' => 'pg-nil', 'note' => 'Patroli LBSM Gunung Mas, kondisi aman', 'latitude' => -5.2, 'longitude' => 105.7,
            'occurred_at' => '2026-10-29T15:00:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
        ])->assertCreated();
        $this->api($account)->postJson("/api/v1/mobile/pasila/{$session->id}/close", [
            'client_request_id' => 'pg-close', 'occurred_at' => '2026-10-29T19:25:00+07:00', 'personnel_ids' => [$this->poskoPerson->id],
        ])->assertCreated()->assertJsonPath('data.closure.flags', ['DITUTUP_SETELAH_SHIFT']);

        $this->assertNull($session->refresh()->minutesUnclosedAfterShift());
        $this->assertNotContains('PSL-20261029-A-PUGUNG', PasilaSession::query()->unclosedAfterShift()->pluck('code')->all());
        $this->flushHeaders();
        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk()->assertSee('data-unclosed-panel="2"', false);
    }

    public function test_duration_labels(): void
    {
        $this->assertSame(
            ['kurang dari 1 menit', '45 menit', '3 jam 20 menit', '1 hari', '1 hari 11 jam', '3 hari'],
            array_map(PasilaSession::durationLabel(...), [0, 45, 200, 1440, 2130, 4359]),
        );
    }

    public function test_panel_states_there_is_nothing_overdue(): void
    {
        PasilaSession::query()->where('status', PasilaSession::STATUS_DIBUKA)->update(['status' => PasilaSession::STATUS_DITUTUP]);

        $this->actingAs($this->admin)->get(route('admin.pasila.index'))->assertOk()
            ->assertSee('data-unclosed-panel="0"', false)
            ->assertSee('Tidak ada sesi PASILA yang melewati akhir shift tanpa penutupan.');
    }

    private function openAt(int $day, int $hour, int $minute): void
    {
        $this->travelTo(Carbon::create(2026, 10, $day, $hour, $minute, 0, ShiftService::TIMEZONE));
        app(PasilaShiftOpener::class)->openCurrent(PasilaSession::TRIGGER_CLI);
    }

    private function sessionOf(string $suffix): PasilaSession
    {
        return PasilaSession::query()->where('code', 'PSL-'.$suffix)->sole();
    }

    private function api(User $account): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$account->createToken('HP', ['field'])->plainTextToken)->withHeader('Accept', 'application/json');
    }
}
