<?php

namespace Tests\Feature\Admin;

use App\Models\User;
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
            ->assertSee('maps-layer-apkuk');
    }
}
