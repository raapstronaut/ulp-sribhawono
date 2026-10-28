<?php

namespace Tests\Feature\Operations;

use App\Models\Penyulang;
use App\Services\Work\LocationTargetResolver;
use App\Support\PenyulangName;
use Illuminate\Support\Facades\DB;

/**
 * B1.3 — alias penyulang terkonfirmasi (TANGGAMUS = TANGGAMUS 2) dipakai saat membaca untuk
 * agregasi, filter, dan pencocokan; nilai mentah sumber tidak diubah dan tidak ada data hilang.
 */
class PenyulangAliasTest extends OperationsTestCase
{
    private Penyulang $tanggamus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tanggamus = Penyulang::create(['kode_penyulang' => 'TANGGAMUS 2', 'nama_penyulang' => 'TANGGAMUS 2']);
        foreach ([['G1', 'TANGGAMUS'], ['G2', 'tanggamus '], ['G3', 'TANGGAMUS 2'], ['G4', 'REOG'], ['G5', 'TANGGAMUS 3']] as [$id, $penyulang]) {
            DB::table('gangguans')->insert([
                'id_gangguan' => $id, 'tanggal_kejadian' => '2026-10-20', 'penyulang' => $penyulang, 'peralatan' => 'REC UJI',
                'lama_padam_menit' => 10, 'sumber_data' => 'PLN_PUSAT', 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_canonical_names_follow_only_confirmed_aliases(): void
    {
        $this->assertSame('TANGGAMUS 2', PenyulangName::canonical('TANGGAMUS'));
        $this->assertSame('TANGGAMUS 2', PenyulangName::canonical(' tanggamus. '));
        $this->assertSame('TANGGAMUS 2', PenyulangName::canonical('TANGGAMUS 2'));
        $this->assertSame('CAKALELE', PenyulangName::canonical('CAKALELE.'));
        $this->assertSame('TANGGAMUS 3', PenyulangName::canonical('TANGGAMUS 3'), 'Nama yang belum terbukti identik tidak ditebak.');
        $this->assertSame(['TANGGAMUS 2', 'TANGGAMUS'], PenyulangName::sourceNames($this->tanggamus));
        $this->assertTrue(LocationTargetResolver::penyulangMatches('Tanggamus', $this->tanggamus));
        $this->assertFalse(LocationTargetResolver::penyulangMatches('TANGGAMUS 3', $this->tanggamus));
    }

    public function test_special_case_groups_alias_under_canonical_name_without_losing_events(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.special-case.index', ['period' => 30]))->assertOk();
        $groups = collect($response->viewData('groups')->items())->pluck('total', 'group_key')->map(fn ($total) => (int) $total)->all();

        $this->assertSame(['TANGGAMUS 2' => 3, 'REOG' => 1, 'TANGGAMUS 3' => 1], $groups);
        $this->assertSame(5, $response->viewData('summary')['total']);

        // Detail per penyulang canonical memuat ketiga kejadian dengan nilai sumber apa adanya.
        $detail = $this->actingAs($this->admin)->get(route('admin.special-case.show', ['dimension' => 'penyulang', 'key' => 'TANGGAMUS 2', 'period' => 30]))->assertOk();
        $this->assertSame(['G1', 'G2', 'G3'], collect($detail->viewData('events')->items())->pluck('id_gangguan')->sort()->values()->all());
        $detail->assertSee('tanggamus');
    }

    public function test_analytics_dashboard_and_master_data_count_alias_once(): void
    {
        $analytics = $this->actingAs($this->admin)->get(route('admin.analytics.index', ['period' => 30]))->assertOk();
        $perPenyulang = collect($analytics->viewData('gangguanPerPenyulang'))->pluck('total', 'penyulang')->map(fn ($total) => (int) $total)->all();
        $this->assertSame(3, $perPenyulang['TANGGAMUS 2']);
        $this->assertArrayNotHasKey('TANGGAMUS', $perPenyulang);
        $this->assertSame(5, array_sum($perPenyulang));

        $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $top = collect($dashboard->viewData('gangguan')['top_penyulang'])->pluck('total', 'name')->all();
        $this->assertSame(3, $top['TANGGAMUS 2']);
        $this->assertArrayNotHasKey('TANGGAMUS', $top);

        $master = $this->actingAs($this->admin)->get(route('admin.master-data.penyulang.show', $this->tanggamus))->assertOk();
        $this->assertSame(3, $master->viewData('sourceCounts')['gangguans']);
    }

    public function test_master_data_counts_source_spelling_with_trailing_dot(): void
    {
        $cakalele = Penyulang::create(['kode_penyulang' => 'CAKALELE', 'nama_penyulang' => 'CAKALELE']);
        $this->apkuk(['penyulang' => 'CAKALELE.', 'no_tiang' => 'F.CAKALELE.000001']);
        $this->apkuk(['penyulang' => 'CAKALELE', 'no_tiang' => 'F.CAKALELE.000002']);

        $master = $this->actingAs($this->admin)->get(route('admin.master-data.penyulang.show', $cakalele))->assertOk();
        $this->assertSame(2, $master->viewData('sourceCounts')['apkuks']);
    }

    public function test_map_exposes_canonical_feeder_and_keeps_source_value(): void
    {
        $gangguans = collect($this->actingAs($this->admin)->getJson(route('admin.maps.data'))->assertOk()->json('gangguans'))->keyBy('id');

        $this->assertSame('TANGGAMUS 2', $gangguans['G1']['penyulang']);
        $this->assertSame('TANGGAMUS', $gangguans['G1']['penyulang_sumber']);
        $this->assertSame('TANGGAMUS 2', $gangguans['G3']['penyulang']);
        $this->assertSame('TANGGAMUS 3', $gangguans['G5']['penyulang']);
    }

    public function test_raw_source_values_are_never_rewritten(): void
    {
        $this->actingAs($this->admin)->get(route('admin.special-case.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.analytics.index'))->assertOk();
        $this->actingAs($this->admin)->getJson(route('admin.maps.data'))->assertOk();

        $this->assertSame(
            ['G1' => 'TANGGAMUS', 'G2' => 'tanggamus ', 'G3' => 'TANGGAMUS 2', 'G4' => 'REOG', 'G5' => 'TANGGAMUS 3'],
            DB::table('gangguans')->orderBy('id_gangguan')->pluck('penyulang', 'id_gangguan')->all(),
        );
        $this->assertSame(2, Penyulang::query()->count(), 'Tidak ada entitas penyulang alias yang dibuat.');
    }
}
