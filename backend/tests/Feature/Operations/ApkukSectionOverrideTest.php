<?php

namespace Tests\Feature\Operations;

use App\Models\Apkuk;
use App\Models\ApkukSectionOverride;
use App\Models\AuditLog;
use App\Models\Penyulang;
use App\Services\Apkuk\ApkukSectionService;
use App\Services\ApkukCandidateService;
use Illuminate\Support\Facades\DB;

/**
 * B1.4 — koreksi Section internal Admin untuk laporan APKUK yang Section sumbernya kosong/DUMMY.
 * Nilai sumber tetap utuh, koreksi tervalidasi terhadap referensi yang ada, tercatat di audit,
 * dapat dicabut, dan tidak memindahkan WO yang sudah dibuat.
 */
class ApkukSectionOverrideTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('section_pic_source_rows')->insert([
            ['penyulang_raw' => 'REOG', 'pemutus_raw' => 'REC BRAJA DEWA', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['penyulang_raw' => null, 'pemutus_raw' => 'LBSM GUNUNG MAS', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_admin_fills_empty_section_and_source_value_stays_intact_with_audit_trail(): void
    {
        $id = $this->apkuk(['section' => null]);

        $this->correct($id, 'rec braja  dewa', 'Konfirmasi petugas Posko Pugung via radio')->assertSessionHasNoErrors();

        $this->assertNull(DB::table('apkuks')->where('id', $id)->value('section'), 'Kolom sumber tidak diubah.');
        $override = ApkukSectionOverride::query()->sole();
        $this->assertSame('REC BRAJA DEWA', $override->section_name);
        $this->assertSame(ApkukSectionOverride::BASIS_SECTION_PIC, $override->reference_basis);
        $this->assertSame($this->admin->id, $override->updated_by_user_id);

        $log = AuditLog::query()->where('entity_type', 'apkuk_section_override')->sole();
        $this->assertSame(AuditLog::ACTION_CREATED, $log->action);
        $this->assertSame((string) $id, $log->entity_id);
        $this->assertNull($log->before);
        $this->assertSame('REC BRAJA DEWA', $log->after['section_koreksi']);
        $this->assertSame('Konfirmasi petugas Posko Pugung via radio', $log->reason);
        $this->assertSame($this->admin->id, $log->actor_user_id);
        $this->assertNotNull($log->created_at);

        $this->actingAs($this->admin)->get(route('admin.apkuk.show', $id))->assertOk()
            ->assertSee('REC BRAJA DEWA')->assertSee('Koreksi Admin (sumber kosong/DUMMY)')->assertSee('Konfirmasi petugas Posko Pugung via radio');
        $this->actingAs($this->admin)->get(route('admin.apkuk.index'))->assertOk()->assertSee('Koreksi Admin: REC BRAJA DEWA');
    }

    public function test_admin_corrects_dummy_section_from_same_penyulang_reference_and_new_work_orders_use_it(): void
    {
        $this->apkuk(['section' => 'SEC PASAR', 'no_tiang' => 'F.REOG.300001']);
        $old = $this->apkuk(['section' => 'DUMMY', 'no_tiang' => 'F.REOG.300002']);
        $oldWo = $this->createApkukWorkOrder($old);
        $this->assertNull($oldWo->section_name);
        $logsBefore = DB::table('work_order_status_logs')->count();

        $this->correct($old, 'SEC PASAR')->assertSessionHasNoErrors();

        $this->assertSame('DUMMY', DB::table('apkuks')->where('id', $old)->value('section'));
        $this->assertSame(ApkukSectionOverride::BASIS_SAME_PENYULANG, ApkukSectionOverride::query()->value('reference_basis'));
        // WO yang sudah ada tidak dipindahkan atau diubah diam-diam.
        $this->assertNull($oldWo->refresh()->section_name);
        $this->assertSame($logsBefore, DB::table('work_order_status_logs')->count());

        // Penilaian kandidat memakai Section efektif.
        $fresh = $this->apkuk(['section' => '-', 'no_tiang' => 'F.REOG.300003']);
        $this->correct($fresh, 'SEC PASAR')->assertSessionHasNoErrors();
        $candidate = app(ApkukCandidateService::class)->find($fresh);
        $this->assertSame('SEC PASAR', $candidate->sectionName);
        $this->assertSame(ApkukSectionService::ORIGIN_OVERRIDE, $candidate->sectionOrigin);
        $this->assertNotContains(ApkukCandidateService::WARNING_SECTION_TIDAK_DIKETAHUI, $candidate->warnings);
        $newWo = $this->createApkukWorkOrder($fresh);
        $this->assertSame('SEC PASAR', $newWo->section_name);
        $this->assertSame(ApkukSectionService::ORIGIN_OVERRIDE, $newWo->statusLogs()->oldest('id')->first()->payload['section_origin']);
    }

    public function test_invalid_section_values_are_rejected(): void
    {
        $id = $this->apkuk(['section' => 'DUMMY']);

        $this->correct($id, 'SECTION KARANGAN')->assertSessionHasErrors('section_name');
        $this->correct($id, 'DUMMY')->assertSessionHasErrors('section_name');
        $this->correct($id, '')->assertSessionHasErrors('section_name');
        $this->correct($id, 'REC BRAJA DEWA', 'pendek')->assertSessionHasErrors('reason');
        $this->assertSame(0, ApkukSectionOverride::query()->count());
        $this->assertSame(0, AuditLog::query()->where('entity_type', 'apkuk_section_override')->count());
    }

    public function test_filled_source_section_cannot_be_overridden_internally(): void
    {
        $id = $this->apkuk(['section' => 'REC BRAJA DEWA']);

        $this->correct($id, 'LBSM GUNUNG MAS')->assertSessionHasErrors('section_name');
        $this->assertSame(0, ApkukSectionOverride::query()->count());
    }

    public function test_revoke_is_audited_and_effective_section_returns_to_source(): void
    {
        $id = $this->apkuk(['section' => 'DUMMY']);
        $this->correct($id, 'REC BRAJA DEWA')->assertSessionHasNoErrors();
        $this->correct($id, 'LBSM GUNUNG MAS', 'Revisi: pemutus terdekat menurut peta')->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('admin.apkuk.section.update', $id), ['action' => 'revoke', 'reason' => 'Batas section belum pasti'])->assertSessionHasNoErrors();

        $override = ApkukSectionOverride::query()->sole();
        $this->assertSame(ApkukSectionOverride::STATUS_REVOKED, $override->status);
        $this->assertNull($override->section_name);
        $this->assertSame(
            [AuditLog::ACTION_CREATED, AuditLog::ACTION_UPDATED, AuditLog::ACTION_REVOKED],
            AuditLog::query()->where('entity_type', 'apkuk_section_override')->orderBy('id')->pluck('action')->all(),
        );
        $revoked = AuditLog::query()->where('action', AuditLog::ACTION_REVOKED)->sole();
        $this->assertSame('LBSM GUNUNG MAS', $revoked->before['section_koreksi']);
        $this->assertNull($revoked->after['section_koreksi']);
        $this->assertNull(app(ApkukCandidateService::class)->find($id)->sectionName);

        $this->actingAs($this->admin)->post(route('admin.apkuk.section.update', $id), ['action' => 'revoke', 'reason' => 'Dicabut lagi tanpa koreksi'])->assertSessionHasErrors('reason');
    }

    public function test_source_filled_later_takes_precedence_and_difference_is_flagged(): void
    {
        $id = $this->apkuk(['section' => 'DUMMY']);
        $this->correct($id, 'REC BRAJA DEWA')->assertSessionHasNoErrors();

        // Pemilik data kemudian mengisi Section di SPS BOS (ditulis apkuk:sync).
        DB::table('apkuks')->where('id', $id)->update(['section' => 'LBSM GUNUNG MAS']);

        $this->assertSame('LBSM GUNUNG MAS', app(ApkukCandidateService::class)->find($id)->sectionName);
        $this->actingAs($this->admin)->get(route('admin.apkuk.show', $id))->assertOk()->assertSee('Sumber kini terisi dan berbeda dari koreksi');
        $this->assertSame(ApkukSectionOverride::STATUS_ACTIVE, ApkukSectionOverride::query()->value('status'), 'Koreksi tidak dihapus otomatis.');
    }

    public function test_section_pic_pemutus_of_another_penyulang_is_neither_offered_nor_accepted(): void
    {
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);
        DB::table('section_pic_source_rows')->insert([
            // Baris sebelum penyulang pertama tidak dapat dipetakan ke penyulang mana pun.
            ['penyulang_raw' => null, 'pemutus_raw' => 'PEMUTUS TANPA PENYULANG', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['penyulang_raw' => 'BEDANA', 'pemutus_raw' => 'LBSM BEDANA UJI', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['penyulang_raw' => null, 'pemutus_raw' => 'REC BEDANA HILIR', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $reog = $this->apkuk(['section' => 'DUMMY']);
        $bedana = $this->apkuk(['section' => 'DUMMY', 'penyulang' => 'BEDANA', 'no_tiang' => 'F.BEDANA.000001']);

        $this->assertSame(['LBSM GUNUNG MAS', 'REC BRAJA DEWA'], array_keys(app(ApkukSectionService::class)->references(Apkuk::query()->findOrFail($reog))));
        $this->assertSame(['LBSM BEDANA UJI', 'REC BEDANA HILIR'], array_keys(app(ApkukSectionService::class)->references(Apkuk::query()->findOrFail($bedana))));

        $this->correct($reog, 'REC BEDANA HILIR')->assertSessionHasErrors('section_name');
        $this->correct($reog, 'PEMUTUS TANPA PENYULANG')->assertSessionHasErrors('section_name');
        $this->correct($bedana, 'REC BRAJA DEWA')->assertSessionHasErrors('section_name');
        $this->assertSame(0, ApkukSectionOverride::query()->count());

        $this->correct($bedana, 'REC BEDANA HILIR')->assertSessionHasNoErrors();
        $this->assertSame(ApkukSectionOverride::BASIS_SECTION_PIC, ApkukSectionOverride::query()->sole()->reference_basis);
    }

    public function test_source_section_mapped_to_another_penyulang_in_section_pic_is_not_offered(): void
    {
        DB::table('section_pic_source_rows')->insert(['penyulang_raw' => 'BEDANA', 'pemutus_raw' => 'LBSM BEDANA UJI', 'posko_pic_raw' => 'PUGUNG', 'source_row_number' => 4, 'created_at' => now(), 'updated_at' => now()]);
        // Laporan REOG lain di sumber menulis Section milik BEDANA (bertentangan) dan Section yang tidak dikenal SECTION_PIC_RAW.
        $this->apkuk(['section' => 'LBSM BEDANA UJI', 'no_tiang' => 'F.REOG.400001']);
        $this->apkuk(['section' => 'SEC PASAR', 'no_tiang' => 'F.REOG.400002']);
        $target = $this->apkuk(['section' => 'DUMMY', 'no_tiang' => 'F.REOG.400003']);

        $references = app(ApkukSectionService::class)->references(Apkuk::query()->findOrFail($target));
        $this->assertArrayNotHasKey('LBSM BEDANA UJI', $references);
        $this->assertSame(ApkukSectionOverride::BASIS_SAME_PENYULANG, $references['SEC PASAR']['basis']);
        $this->correct($target, 'LBSM BEDANA UJI')->assertSessionHasErrors('section_name');
        $this->correct($target, 'SEC PASAR')->assertSessionHasNoErrors();
    }

    public function test_report_without_master_penyulang_has_no_references(): void
    {
        $id = $this->apkuk(['section' => 'DUMMY', 'penyulang' => 'DUMMY']);
        $this->apkuk(['section' => 'SEC LAIN', 'penyulang' => 'DUMMY', 'no_tiang' => 'F.DUMMY.000002']);

        $this->assertSame([], app(ApkukSectionService::class)->references(Apkuk::query()->findOrFail($id)));
        $this->correct($id, 'REC BRAJA DEWA')->assertSessionHasErrors('section_name');
        $this->actingAs($this->admin)->get(route('admin.apkuk.show', $id))->assertOk()->assertSee('Belum ada referensi Section.');
    }

    public function test_non_admin_and_guest_cannot_correct_section(): void
    {
        $id = $this->apkuk(['section' => 'DUMMY']);
        $payload = ['action' => 'set', 'section_name' => 'REC BRAJA DEWA', 'reason' => 'Percobaan tanpa hak akses'];

        $this->post(route('admin.apkuk.section.update', $id), $payload)->assertRedirect(route('login'));
        $this->actingAs($this->fieldAccount($this->poskoTeam, 'posko_section'))->post(route('admin.apkuk.section.update', $id), $payload)->assertForbidden();
        $this->assertSame(0, ApkukSectionOverride::query()->count());
    }

    private function correct(int $id, string $section, string $reason = 'Konfirmasi lapangan oleh tim Posko')
    {
        return $this->actingAs($this->admin)->post(route('admin.apkuk.section.update', $id), [
            'action' => 'set', 'section_name' => $section, 'reason' => $reason,
        ]);
    }
}
