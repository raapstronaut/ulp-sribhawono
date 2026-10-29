<?php

namespace Tests\Feature\Operations;

use App\Models\Apkuk;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Services\Apkuk\ApkukSectionService;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\ApkukCandidateService;
use Illuminate\Support\Facades\DB;

/**
 * B2.2 — Posko penanggung jawab kandidat APKUK hanya dari pemetaan SECTION_PIC_RAW yang terbukti
 * (Section → pemutus pada penyulang yang sama → satu POSKO PIC). Tidak ditebak dari penyulang.
 */
class SectionPoskoMapTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['WAY JEPARA', 'SRIBHAWONO', 'JABUNG'] as $name) {
            Posko::create(['kode_posko' => $name, 'nama_posko' => $name]);
        }
        Penyulang::create(['kode_penyulang' => 'BEDANA', 'nama_penyulang' => 'BEDANA']);
        Penyulang::create(['kode_penyulang' => 'TANGGAMUS 2', 'nama_penyulang' => 'TANGGAMUS 2']);

        $rows = [
            ['REOG', 'REC BRAJA DEWA', 'WAY JEPARA'],
            [null, 'LBSM GUNUNG MAS', 'PUGUNG'],
            [null, 'LBSM AMBIGU', 'PUGUNG'],
            [null, 'LBSM AMBIGU', 'JABUNG'],
            [null, 'LBSM TANPA PIC', ''],
            [null, 'LBSM POSKO ASING', 'POSKO LUAR ULP'],
            [null, 'LBSM BERSAMA', 'PUGUNG'],
            ['TANGGAMUS', 'REC KETAPANG', 'SRIBAWONO'],
            ['BEDANA', 'LBSM BEDANA', 'PUGUNG'],
            [null, 'LBSM BERSAMA', 'PUGUNG'],
        ];
        foreach ($rows as $i => [$penyulang, $pemutus, $posko]) {
            DB::table('section_pic_source_rows')->insert([
                'penyulang_raw' => $penyulang, 'pemutus_raw' => $pemutus, 'posko_pic_raw' => $posko,
                'source_row_number' => $i + 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_valid_mapping_resolves_single_posko_pic_with_normalized_section(): void
    {
        $result = SectionPoskoMap::load()->resolve('REOG', ' rec braja  dewa ');

        $this->assertSame('WAY JEPARA', $result['posko']?->nama_posko);
        $this->assertNull($result['reason']);
        $this->assertSame('REC BRAJA DEWA', $result['section']);
    }

    public function test_empty_and_dummy_sections_are_held(): void
    {
        $map = SectionPoskoMap::load();

        $this->assertSame(SectionPoskoMap::REASON_SECTION_KOSONG, $map->resolve('REOG', null)['reason']);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_KOSONG, $map->resolve('REOG', ' - ')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_DUMMY, $map->resolve('REOG', 'dummy')['reason']);
    }

    public function test_internal_section_correction_must_still_pass_posko_mapping(): void
    {
        $mapped = $this->apkuk(['section' => 'DUMMY', 'no_tiang' => 'F.REOG.500001']);
        $this->apkuk(['section' => 'SEC PASAR', 'no_tiang' => 'F.REOG.500002']);
        $unmapped = $this->apkuk(['section' => 'DUMMY', 'no_tiang' => 'F.REOG.500003']);
        $sections = app(ApkukSectionService::class);
        $sections->set(Apkuk::query()->findOrFail($mapped), $this->admin, 'LBSM GUNUNG MAS', 'Konfirmasi petugas Posko Pugung');
        // Referensi B1 yang sah (Section laporan lain sepenyulang), tetapi tidak ada di SECTION_PIC_RAW.
        $sections->set(Apkuk::query()->findOrFail($unmapped), $this->admin, 'SEC PASAR', 'Konfirmasi petugas lapangan');

        $candidates = app(ApkukCandidateService::class)->evaluate();
        $map = SectionPoskoMap::load();

        $this->assertSame('PUGUNG', $map->resolve('REOG', $candidates[$mapped]->sectionName)['posko']?->nama_posko);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_TIDAK_DIKENAL, $map->resolve('REOG', $candidates[$unmapped]->sectionName)['reason']);
        $this->assertSame('DUMMY', DB::table('apkuks')->where('id', $mapped)->value('section'), 'Nilai sumber tetap.');
    }

    public function test_section_of_another_feeder_and_multi_feeder_sections_are_held(): void
    {
        $map = SectionPoskoMap::load();

        $this->assertSame(SectionPoskoMap::REASON_SECTION_PENYULANG_LAIN, $map->resolve('REOG', 'LBSM BEDANA')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_MULTI_PENYULANG, $map->resolve('REOG', 'LBSM BERSAMA')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_MULTI_PENYULANG, $map->resolve('BEDANA', 'LBSM BERSAMA')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_SECTION_TIDAK_DIKENAL, $map->resolve('REOG', 'SECTION KARANGAN')['reason']);
    }

    public function test_feeder_and_posko_aliases_follow_confirmed_spellings_only(): void
    {
        $map = SectionPoskoMap::load();

        // SECTION_PIC_RAW menulis "TANGGAMUS" dan Posko "SRIBAWONO"; nama resmi TANGGAMUS 2 dan SRIBHAWONO.
        $this->assertSame('SRIBHAWONO', $map->resolve('TANGGAMUS 2', 'REC KETAPANG')['posko']?->nama_posko);
        $this->assertEqualsCanonicalizing(['LBSM BEDANA' => 'LBSM BEDANA', 'LBSM BERSAMA' => 'LBSM BERSAMA'], $map->sectionsOf('BEDANA'));
    }

    public function test_unknown_blank_and_ambiguous_posko_pic_are_held(): void
    {
        $map = SectionPoskoMap::load();

        $this->assertSame(SectionPoskoMap::REASON_POSKO_TIDAK_DIKENAL, $map->resolve('REOG', 'LBSM POSKO ASING')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_POSKO_PIC_KOSONG, $map->resolve('REOG', 'LBSM TANPA PIC')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_POSKO_AMBIGU, $map->resolve('REOG', 'LBSM AMBIGU')['reason']);
        $this->assertSame(SectionPoskoMap::REASON_PENYULANG_TIDAK_DIKENAL, $map->resolve('', 'REC BRAJA DEWA')['reason']);
    }
}
