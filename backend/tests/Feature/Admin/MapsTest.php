<?php

namespace Tests\Feature\Admin;

use App\Models\ApkukReconciliationItem;
use App\Models\User;
use App\Support\OperationalArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_and_non_admin_cannot_access_maps_data(): void
    {
        $this->get('/admin/maps/data')->assertRedirect(route('login'));

        $user = User::factory()->create([
            'role' => 'POSKO',
            'is_active' => true,
        ]);

        $this->actingAs($user)->getJson('/admin/maps/data')->assertForbidden();
    }

    public function test_admin_maps_data_includes_valid_locations_and_preserves_history(): void
    {
        DB::table('keypoints')->insert([
            'legacy_key' => str_repeat('a', 64),
            'penyulang' => 'REOG',
            'jenis' => 'RECLOSER',
            'nama' => 'REC TEST',
            'latitude' => -5.22,
            'longitude' => 105.73,
            'sumber_input' => 'SPS_BOS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apkuk = [
            'legacy_key' => str_repeat('b', 64),
            'source_id' => 'APKUK-1',
            'penyulang' => 'REOG',
            'no_tiang' => 'F.REOG.000001',
            'latitude' => -5.23,
            'longitude' => 105.74,
            'sumber_input' => 'APPSHEET_LAMA',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('apkuks')->insert($apkuk);
        DB::table('apkuks')->insert(array_merge($apkuk, [
            'legacy_key' => str_repeat('c', 64),
            'source_id' => 'APKUK-ARCHIVE',
            'no_tiang' => 'F.REOG.000002',
            'source_missing_at' => now(),
        ]));
        DB::table('apkuks')->insert(array_merge($apkuk, [
            'legacy_key' => str_repeat('d', 64),
            'source_id' => 'APKUK-NOGPS',
            'no_tiang' => 'F.REOG.000003',
            'latitude' => null,
            'longitude' => null,
        ]));

        DB::table('gangguans')->insert([
            'id_gangguan' => 'TEST-GANGGUAN-1',
            'penyulang' => 'REOG',
            'peralatan' => 'REC TEST',
            'tanggal_kejadian' => '2026-10-25',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->getJson('/admin/maps/data')
            ->assertOk()
            ->assertJsonCount(1, 'keypoints')
            ->assertJsonCount(1, 'apkuks')
            ->assertJsonCount(1, 'gangguans')
            ->assertJsonPath('meta.apkuk_active_total', 2)
            ->assertJsonPath('meta.apkuk_mapped', 1)
            ->assertJsonPath('meta.apkuk_without_coordinates', 1)
            ->assertJsonPath('meta.gangguan_mapped', 0)
            ->assertJsonPath('apkuks.0.no_tiang', 'F.REOG.000001');
    }

    public function test_admin_can_open_map_page(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get('/admin/maps')
            ->assertOk()
            ->assertSee('maps-layer-keypoint')
            ->assertSee('maps-layer-apkuk')
            ->assertSee('maps-tab-issues')
            ->assertSee('Koordinat Invalid');
    }

    /**
     * Kasus nyata APKUK_RAW: A91 longitude 0, dan A104 longitude 105,12 (±35 km di luar jaringan).
     * Titik seperti ini dulu ikut menentukan fitBounds sehingga peta terbuka sampai seluruh dunia.
     */
    public function test_invalid_coordinates_are_not_mapped_but_reported_with_their_original_values(): void
    {
        $apkuk = fn (string $id, ?float $lat, ?float $lon) => DB::table('apkuks')->insertGetId([
            'legacy_key' => hash('sha256', $id),
            'source_id' => $id,
            'tanggal' => '2026-08-01',
            'penyulang' => 'JAIPONG',
            'no_tiang' => "F.jaipong.{$id}",
            'status' => 'SELESAI',
            'latitude' => $lat,
            'longitude' => $lon,
            'sumber_input' => 'APPSHEET_LAMA',
            'source_row_number' => 1676,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $apkuk('VALID', -5.354, 105.78);
        $zeroLon = $apkuk('A91', -5.354, 0.0);
        $apkuk('A104', -5.3508, 105.12);
        $apkuk('NOL', 0.0, 0.0);
        $apkuk('TERTUKAR', 105.78, -5.354);
        $apkuk('NOGPS', null, null);
        $pendingId = $apkuk('PENDING', -5.354, 0.0);
        ApkukReconciliationItem::query()->create([
            'kind' => ApkukReconciliationItem::KIND_MISSING, 'status' => ApkukReconciliationItem::STATUS_OPEN,
            'open_key' => ApkukReconciliationItem::openKey(ApkukReconciliationItem::KIND_MISSING, hash('sha256', 'PENDING')), 'apkuk_id' => $pendingId,
            'legacy_key' => hash('sha256', 'PENDING'), 'source_id' => 'PENDING', 'no_tiang' => 'F.jaipong.PENDING', 'first_detected_at' => now(), 'last_detected_at' => now(),
        ]);
        foreach ([['VALID', -5.22, 105.73], ['LUAR', -3.0, 104.0]] as [$name, $lat, $lon]) {
            DB::table('keypoints')->insert([
                'legacy_key' => hash('sha256', $name), 'penyulang' => 'REOG', 'jenis' => 'FCO', 'nama' => "FCO {$name}",
                'latitude' => $lat, 'longitude' => $lon, 'sumber_input' => 'SPS_BOS', 'source_row_number' => 9, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $before = DB::table('apkuks')->orderBy('id')->get()->toArray();

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/admin/maps/data')->assertOk()
            ->assertJsonPath('meta.keypoint_mapped', 1)
            ->assertJsonPath('meta.keypoint_invalid_coordinates', 1)
            ->assertJsonPath('meta.apkuk_active_total', 6)
            ->assertJsonPath('meta.apkuk_mapped', 1)
            ->assertJsonPath('meta.apkuk_invalid_coordinates', 4)
            ->assertJsonPath('meta.apkuk_without_coordinates', 1)
            ->assertJsonPath('meta.apkuk_pending_reconciliation', 1)
            ->assertJsonPath('meta.apkuk_pending_mapped', 0)
            ->assertJsonPath('meta.operational_bounds', config('maps.operational_bounds'));

        // Semua koordinat marker berada di dalam wilayah operasional, jadi batas tampilan peta aman.
        $this->assertSame(['FCO VALID'], array_column($response->json('keypoints'), 'nama'));
        $this->assertSame(['VALID'], array_column($response->json('apkuks'), 'source_id'));

        $issues = collect($response->json('coordinate_issues'))->keyBy(fn (array $i) => $i['source_id'] ?? $i['label']);
        $this->assertEqualsCanonicalizing(['FCO LUAR', 'A91', 'A104', 'NOL', 'TERTUKAR', 'PENDING'], $issues->keys()->all());
        $this->assertSame([-5.354, 0, OperationalArea::ISSUE_ZERO, 1676, false], [$issues['A91']['latitude'], $issues['A91']['longitude'], $issues['A91']['issue'], $issues['A91']['source_row_number'], $issues['A91']['reconciliation_pending']]);
        $this->assertSame([105.12, OperationalArea::ISSUE_OUTSIDE], [$issues['A104']['longitude'], $issues['A104']['issue']]);
        $this->assertSame(OperationalArea::ISSUE_ZERO, $issues['NOL']['issue']);
        $this->assertSame([105.78, OperationalArea::ISSUE_OUTSIDE], [$issues['TERTUKAR']['latitude'], $issues['TERTUKAR']['issue']]);
        $this->assertTrue($issues['PENDING']['reconciliation_pending'], 'Antrean rekonsiliasi tetap bertanda dan tidak dihitung aktif.');
        $this->assertSame('keypoint', $issues['FCO LUAR']['layer']);
        $this->assertSame(OperationalArea::ISSUE_LABELS[OperationalArea::ISSUE_ZERO], $issues['A91']['issue_label']);

        $this->assertEquals($before, DB::table('apkuks')->orderBy('id')->get()->toArray(), 'Laporan APKUK dan koordinatnya tidak diubah.');
    }

    public function test_operational_area_bounds_are_inclusive_and_cover_the_real_network_spread(): void
    {
        // Titik ekstrem sebaran ASSET_RAW, Keypoint, dan APKUK yang sah.
        foreach ([[-5.72706, 105.45236], [-4.91773, 105.8629], [-5.60768, 105.46007], [-5.03802, 105.82611]] as [$lat, $lon]) {
            $this->assertNull(OperationalArea::issue($lat, $lon), "{$lat}, {$lon}");
        }
        $b = OperationalArea::bounds();
        $this->assertNull(OperationalArea::issue($b['south'], $b['west']));
        $this->assertNull(OperationalArea::issue($b['north'], $b['east']));
        $this->assertSame(OperationalArea::ISSUE_OUTSIDE, OperationalArea::issue($b['south'] - 0.01, $b['west']));
        $this->assertSame(OperationalArea::ISSUE_OUTSIDE, OperationalArea::issue($b['north'], $b['east'] + 0.01));
        $this->assertSame(OperationalArea::ISSUE_ZERO, OperationalArea::issue(-5.354, 0.0));
        $this->assertSame(OperationalArea::ISSUE_ZERO, OperationalArea::issue(0.0, 105.7));
    }
}
