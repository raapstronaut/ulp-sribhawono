<?php

namespace Tests\Feature\WorkOrders;

use App\Models\Penyulang;
use App\Services\ApkukCandidateService as Candidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApkukCandidateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);
        Penyulang::create(['kode_penyulang' => 'CAKALELE', 'nama_penyulang' => 'CAKALELE']);
    }

    public function test_positive_need_with_open_source_status_is_eligible(): void
    {
        $belum = $this->apkuk(['no_tiang' => 'F.REOG.000001', 'kurang_bh' => 3, 'status' => 'BELUM EKSEKUSI']);
        $kurang = $this->apkuk(['no_tiang' => 'F.REOG.000002', 'kurang_bh' => 1, 'status' => 'KURANG']);

        $candidates = app(Candidates::class)->evaluate();

        $this->assertTrue($candidates[$belum]->isEligible());
        $this->assertSame(3, $candidates[$belum]->targetQuantity());
        $this->assertSame('REOG|F.REOG.000001', $candidates[$belum]->targetKey);
        $this->assertTrue($candidates[$kurang]->isEligible());
    }

    public function test_empty_zero_and_negative_needs_are_not_candidates(): void
    {
        $empty = $this->apkuk(['no_tiang' => 'F.REOG.000010', 'kurang_bh' => null]);
        $zero = $this->apkuk(['no_tiang' => 'F.REOG.000011', 'kurang_bh' => 0, 'status' => 'SELESAI']);
        $negative = $this->apkuk(['no_tiang' => 'F.REOG.000012', 'kurang_bh' => -2]);

        $candidates = app(Candidates::class)->evaluate();

        $this->assertSame([Candidates::ISSUE_KURANG_KOSONG], $candidates[$empty]->issues);
        $this->assertSame([Candidates::ISSUE_TIDAK_ADA_KEBUTUHAN], $candidates[$zero]->issues);
        $this->assertSame([Candidates::ISSUE_KURANG_NEGATIF], $candidates[$negative]->issues);
    }

    public function test_ambiguous_source_status_is_not_treated_as_need(): void
    {
        $contradictory = $this->apkuk(['no_tiang' => 'F.REOG.000020', 'kurang_bh' => 4, 'status' => 'SELESAI']);
        $unknown = $this->apkuk(['no_tiang' => 'F.REOG.000021', 'kurang_bh' => 4, 'status' => null]);

        $candidates = app(Candidates::class)->evaluate();

        $this->assertSame([Candidates::ISSUE_STATUS_BERTENTANGAN], $candidates[$contradictory]->issues);
        $this->assertSame([Candidates::ISSUE_STATUS_TIDAK_DIKENAL], $candidates[$unknown]->issues);
    }

    public function test_penyulang_is_matched_to_master_without_guessing(): void
    {
        $trailingDot = $this->apkuk(['penyulang' => ' cakalele. ', 'no_tiang' => 'F.CAKALELE.000001']);
        $dummy = $this->apkuk(['penyulang' => 'Dummy', 'no_tiang' => 'F.X.000001']);

        $candidates = app(Candidates::class)->evaluate();

        $this->assertTrue($candidates[$trailingDot]->isEligible());
        $this->assertSame('CAKALELE', $candidates[$trailingDot]->penyulang->kode_penyulang);
        $this->assertSame([Candidates::ISSUE_PENYULANG_TIDAK_DIKENAL], $candidates[$dummy]->issues);
        $this->assertNull($candidates[$dummy]->penyulang);
    }

    public function test_only_latest_report_per_pole_is_evaluated(): void
    {
        $old = $this->apkuk(['no_tiang' => 'F.REOG.000030', 'tanggal' => '2026-03-01', 'kurang_bh' => 5]);
        // Same pole, different spacing/case, newer report shows it is complete.
        $new = $this->apkuk(['no_tiang' => ' f.reog.000030 ', 'tanggal' => '2026-04-01', 'kurang_bh' => 0, 'status' => 'SELESAI']);

        $candidates = app(Candidates::class)->evaluate();

        $this->assertSame($candidates[$old]->targetKey, $candidates[$new]->targetKey);
        $this->assertContains(Candidates::ISSUE_ADA_LAPORAN_LEBIH_BARU, $candidates[$old]->issues);
        $this->assertFalse($candidates[$new]->isEligible());
    }

    public function test_multiple_latest_reports_on_same_date_are_ambiguous(): void
    {
        $a = $this->apkuk(['no_tiang' => 'F.REOG.000040', 'tanggal' => '2026-03-01', 'kurang_bh' => 2]);
        $b = $this->apkuk(['no_tiang' => 'F.REOG.000040', 'tanggal' => '2026-03-01', 'kurang_bh' => 6, 'source_id' => 'other']);

        $candidates = app(Candidates::class)->evaluate();

        $this->assertSame([Candidates::ISSUE_LAPORAN_GANDA_AMBIGU], $candidates[$a]->issues);
        $this->assertSame([Candidates::ISSUE_LAPORAN_GANDA_AMBIGU], $candidates[$b]->issues);
    }

    public function test_placeholder_section_is_only_a_warning(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.000050', 'section' => 'Dummy']);

        $candidate = app(Candidates::class)->evaluate()[$id];

        $this->assertTrue($candidate->isEligible());
        $this->assertNull($candidate->sectionName);
        $this->assertSame([Candidates::WARNING_SECTION_TIDAK_DIKETAHUI], $candidate->warnings);
    }

    public function test_filter_returns_eligible_candidates_largest_need_first(): void
    {
        $this->apkuk(['no_tiang' => 'F.REOG.000061', 'kurang_bh' => 2]);
        $this->apkuk(['no_tiang' => 'F.REOG.000062', 'kurang_bh' => 7]);
        $this->apkuk(['no_tiang' => 'F.REOG.000063', 'kurang_bh' => 0, 'status' => 'SELESAI']);
        $this->apkuk(['penyulang' => 'CAKALELE', 'no_tiang' => 'F.CAKALELE.000064', 'kurang_bh' => 9]);

        $service = app(Candidates::class);
        $reog = Penyulang::where('kode_penyulang', 'REOG')->first();
        $filtered = $service->filter($service->evaluate(), $reog->id, null);

        $this->assertSame(['F.REOG.000062', 'F.REOG.000061'], $filtered->map(fn ($c) => $c->apkuk->no_tiang)->all());

        $summary = $service->summary($service->evaluate());
        $this->assertSame(4, $summary['total']);
        $this->assertSame(3, $summary['eligible']);
        $this->assertSame([Candidates::ISSUE_TIDAK_ADA_KEBUTUHAN => 1], $summary['issues']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function apkuk(array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        return DB::table('apkuks')->insertGetId(array_merge([
            'source_id' => 'SRC'.$sequence,
            'legacy_key' => hash('sha256', 'test|'.$sequence.'|'.uniqid('', true)),
            'tanggal' => '2026-03-11',
            'penyulang' => 'REOG',
            'section' => 'REC BRAJA DEWA',
            'no_tiang' => 'F.REOG.'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'naik_dari' => 'KABEL SR',
            'terpasang_bh' => 0,
            'kurang_bh' => 2,
            'status' => 'BELUM EKSEKUSI',
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'sumber_input' => 'APPSHEET_LAMA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
