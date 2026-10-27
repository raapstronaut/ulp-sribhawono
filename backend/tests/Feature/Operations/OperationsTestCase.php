<?php

namespace Tests\Feature\Operations;

use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fixture modul operasional Batch A. Data uji, bukan data produksi.
 * Waktu dikunci 28 Okt 2026 09:00 WIB (shift A 08–16).
 */
abstract class OperationsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Posko $posko;

    protected Penyulang $penyulang;

    protected FieldTeam $poskoTeam;

    protected FieldTeam $harsusTeam;

    protected FieldTeam $rowTeam;

    protected FieldTeam $inspeksiTeam;

    protected FieldPersonnel $poskoPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::create(2026, 10, 28, 9, 0, 0, 'Asia/Jakarta'));

        $this->admin = User::factory()->admin()->create(['username' => 'OPS_ADMIN']);
        $this->posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $this->penyulang = Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);

        $this->poskoTeam = FieldTeam::create(['code' => 'POSKO-PUGUNG-A', 'name' => 'Tim Pugung A', 'role' => 'POSKO', 'posko_id' => $this->posko->id]);
        $this->harsusTeam = FieldTeam::create(['code' => 'HARSUS-1', 'name' => 'Tim HARSUS', 'role' => 'HARSUS']);
        $this->rowTeam = FieldTeam::create(['code' => 'ROW-1', 'name' => 'Tim ROW', 'role' => 'ROW']);
        $this->inspeksiTeam = FieldTeam::create(['code' => 'INSPEKSI-1', 'name' => 'Tim Inspeksi', 'role' => 'INSPEKSI']);
        $this->poskoPerson = $this->poskoTeam->personnel()->create(['name' => 'Budi Posko']);
        $this->harsusTeam->personnel()->create(['name' => 'Sari Harsus']);
        $this->rowTeam->personnel()->create(['name' => 'Joko ROW']);
        $this->inspeksiTeam->personnel()->create(['name' => 'Rina Inspeksi']);

        foreach ([
            [ReferenceCode::CATEGORY_RESCHEDULE_REASON, 'CUACA', 'Cuaca buruk'],
            [ReferenceCode::CATEGORY_ESCALATION_REASON, 'BUTUH_PADAM', 'Membutuhkan pemadaman'],
            [ReferenceCode::CATEGORY_INSPECTION_FINDING, 'POHON_DEKAT', 'Pohon dekat jaringan'],
        ] as [$category, $code, $label]) {
            ReferenceCode::create(['category' => $category, 'code' => $code, 'label' => $label]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function apkuk(array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        return DB::table('apkuks')->insertGetId(array_merge([
            'source_id' => 'OPS'.$sequence,
            'legacy_key' => hash('sha256', 'ops|'.$sequence.'|'.uniqid('', true)),
            'tanggal' => '2026-10-01',
            'penyulang' => 'REOG',
            'section' => 'SEC 1',
            'no_tiang' => 'F.REOG.7'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'terpasang_bh' => 0,
            'kurang_bh' => 3,
            'status' => 'BELUM EKSEKUSI',
            'latitude' => -5.2,
            'longitude' => 105.7,
            'sumber_input' => 'APPSHEET_LAMA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function keypoint(array $overrides = []): int
    {
        return DB::table('keypoints')->insertGetId(array_merge([
            'penyulang' => 'REOG',
            'jenis' => 'FCO',
            'nama' => 'FCO REOG 01',
            'latitude' => -5.21,
            'longitude' => 105.71,
            'sumber_input' => 'SPS_BOS',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function vegetation(array $overrides = []): int
    {
        return DB::table('vegetasi_source_rows')->insertGetId(array_merge([
            'id_pohon_raw' => 'PH-001',
            'nama_pohon_raw' => 'Bambu',
            'penyulang_raw' => 'REOG',
            'nama_tiang_raw' => 'F.REOG.000123',
            'kondisi_raw' => 'BAHAYA',
            'latitude_raw' => '-5,2222',
            'longitude_raw' => '105,7777',
            'source_row_number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function photo(string $name = 'bukti.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    protected function fieldAccount(FieldTeam $team, string $username): User
    {
        $user = User::factory()->create(['username' => $username, 'password' => 'rahasia123']);
        $user->forceFill(['role' => $team->role, 'field_team_id' => $team->id, 'is_active' => true])->save();

        return $user;
    }

    protected function createApkukWorkOrder(int $apkukId): WorkOrder
    {
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), [
            'apkuk_id' => $apkukId,
            'posko_id' => $this->posko->id,
            'work_date' => '2026-10-28',
            'shift_code' => 'A',
        ])->assertSessionHasNoErrors();

        return WorkOrder::query()->whereHas('apkukTarget', fn ($target) => $target->where('apkuk_id', $apkukId))->sole();
    }
}
