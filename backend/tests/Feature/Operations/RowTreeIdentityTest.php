<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * B1.1 — target WO ROW diidentifikasi dengan ID POHON (VEGETASI_RAW kolom AB), bukan ID baris
 * staging yang berubah setiap import `--replace`.
 */
class RowTreeIdentityTest extends OperationsTestCase
{
    public function test_target_key_is_id_pohon_and_survives_staging_reimport_with_new_row_order(): void
    {
        $original = $this->vegetation(['id_pohon_raw' => '118258', 'id_tiang_raw' => '17TG0121008524']);
        $wo = $this->createRow($original, WorkOrder::TYPE_RABAS);
        $target = $wo->locationTarget;

        $this->assertSame('POHON:118258', $target->target_key);
        $this->assertSame('118258', $target->source_ref);
        $this->assertSame('ROW|RABAS|POHON:118258', $target->active_target_key);
        $this->assertSame('ID_POHON', $target->snapshot['identity']);
        $this->assertSame($original, $target->snapshot['vegetasi_source_row_id']);

        // Import ulang --replace: baris lama dihapus, urutan berubah, ID baris staging baru.
        DB::table('vegetasi_source_rows')->delete();
        $other = $this->vegetation(['id_pohon_raw' => '119561', 'id_tiang_raw' => '17TG0121007000', 'source_row_number' => 2]);
        $reimported = $this->vegetation(['id_pohon_raw' => '118258', 'id_tiang_raw' => '17TG0121008524', 'source_row_number' => 3]);
        $this->assertNotSame($original, $reimported);

        // Pohon yang sama lewat baris staging baru tetap terkunci oleh WO aktif.
        $this->postRow($reimported, WorkOrder::TYPE_RABAS, '118258')->assertSessionHasErrors('target');
        $this->assertSame(1, WorkOrder::query()->count());

        // WO lama tetap menunjuk pohon yang sama (snapshot tidak berubah).
        $this->assertSame('POHON:118258', $wo->locationTarget->refresh()->target_key);
        $this->actingAs($this->admin)->get(route('admin.work-orders.show', $wo))->assertOk()->assertSee('ID POHON 118258');

        // Pohon lain tetap dapat dibuatkan WO.
        $this->postRow($other, WorkOrder::TYPE_RABAS, '119561')->assertSessionHasNoErrors();
        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_staging_row_reused_by_another_tree_does_not_move_existing_work_order(): void
    {
        $row = $this->vegetation(['id_pohon_raw' => '118258', 'id_tiang_raw' => '17TG01']);
        $wo = $this->createRow($row, WorkOrder::TYPE_PANGKAS);

        // ID baris yang sama kini berisi pohon lain (mis. identity PostgreSQL di-reset saat import).
        DB::table('vegetasi_source_rows')->where('id', $row)->update(['id_pohon_raw' => '777001', 'nama_pohon_raw' => 'Mahoni']);

        $this->assertSame('POHON:118258', $wo->locationTarget->refresh()->target_key);
        $this->postRow($row, WorkOrder::TYPE_PANGKAS, '777001')->assertSessionHasNoErrors();
        $this->assertSame(
            ['POHON:118258', 'POHON:777001'],
            WorkOrderLocationTarget::query()->orderBy('target_key')->pluck('target_key')->all(),
        );
    }

    public function test_form_opened_before_reimport_is_rejected_when_row_now_holds_another_tree(): void
    {
        $row = $this->vegetation(['id_pohon_raw' => '118258']);
        DB::table('vegetasi_source_rows')->where('id', $row)->update(['id_pohon_raw' => '999999']);

        $this->postRow($row, WorkOrder::TYPE_RABAS, '118258')->assertSessionHasErrors('vegetation_id');
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_two_trees_on_the_same_pole_are_separate_targets(): void
    {
        $first = $this->vegetation(['id_pohon_raw' => '200001', 'id_tiang_raw' => '17TG0121002722']);
        $second = $this->vegetation(['id_pohon_raw' => '200002', 'id_tiang_raw' => '17TG0121002722']);

        $this->createRow($first, WorkOrder::TYPE_TEBANG);
        $this->createRow($second, WorkOrder::TYPE_TEBANG);

        $this->assertSame(2, WorkOrder::query()->where('status', WorkOrderStatus::Ditugaskan->value)->count());
    }

    public function test_same_tree_different_row_work_type_is_still_blocked(): void
    {
        $tree = $this->vegetation(['id_pohon_raw' => '220001', 'id_tiang_raw' => '17TG0121009999']);
        $this->createRow($tree, WorkOrder::TYPE_RABAS);

        $this->postRow($tree, WorkOrder::TYPE_PANGKAS, '220001')->assertSessionHasErrors('target');
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_blank_id_pohon_is_rejected_without_inventing_an_identity(): void
    {
        $row = $this->vegetation(['id_pohon_raw' => '  ']);

        $this->postRow($row, WorkOrder::TYPE_RABAS)->assertSessionHasErrors('vegetation_id');
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_duplicate_id_pohon_rows_of_the_same_tree_share_one_identity(): void
    {
        // Import tanpa --replace menggandakan baris: pohon yang sama, ID baris berbeda.
        $first = $this->vegetation(['id_pohon_raw' => '300001', 'id_tiang_raw' => '17TG03']);
        $duplicate = $this->vegetation(['id_pohon_raw' => ' 300001 ', 'penyulang_raw' => 'reog.', 'id_tiang_raw' => '17TG03']);

        $this->createRow($first, WorkOrder::TYPE_RABAS);
        $this->postRow($duplicate, WorkOrder::TYPE_RABAS, '300001')->assertSessionHasErrors('target');
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_conflicting_duplicate_id_pohon_is_rejected_for_clarification(): void
    {
        $row = $this->vegetation(['id_pohon_raw' => '400001', 'id_tiang_raw' => '17TG04-A']);
        $this->vegetation(['id_pohon_raw' => '400001', 'id_tiang_raw' => '17TG04-B']);

        $this->postRow($row, WorkOrder::TYPE_RABAS, '400001')
            ->assertSessionHasErrors(['vegetation_id' => 'ID POHON 400001 tercatat pada 2 baris sumber dengan penyulang/ID tiang berbeda. Klarifikasi ke pemilik data SPS BOS sebelum dibuatkan WO.']);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_finished_work_order_releases_the_tree_for_a_new_cycle(): void
    {
        $row = $this->vegetation(['id_pohon_raw' => '500001']);
        $wo = $this->createRow($row, WorkOrder::TYPE_RABAS);
        $this->actingAs($this->admin)->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Salah target uji'])->assertSessionHasNoErrors();

        $this->assertNull($wo->locationTarget->refresh()->active_target_key);
        $this->assertSame('POHON:500001', $wo->locationTarget->target_key, 'Identitas histori tetap.');
        $this->postRow($row, WorkOrder::TYPE_RABAS, '500001')->assertSessionHasNoErrors();
        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_legacy_targets_are_rekeyed_by_snapshot_id_pohon_and_restored_on_rollback(): void
    {
        $migration = require database_path('migrations/2026_10_28_200001_key_vegetation_work_order_targets_by_id_pohon.php');
        $active = $this->legacyTarget('VEGETASI:11', 'ROW|RABAS|VEGETASI:11', ['id_pohon' => '600001']);
        // WO aktif lama kedua pada pohon yang sama (lolos guard lama karena ID baris berbeda).
        $clash = $this->legacyTarget('VEGETASI:12', 'ROW|RABAS|VEGETASI:12', ['id_pohon' => '600001']);
        $finished = $this->legacyTarget('VEGETASI:13', null, ['id_pohon' => '600002']);
        $unknown = $this->legacyTarget('VEGETASI:14', 'ROW|RABAS|VEGETASI:14', ['nama_pohon' => 'tanpa ID']);
        $before = DB::table('work_order_location_targets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $migration->up();

        $row = fn (string $id) => DB::table('work_order_location_targets')->where('id', $id)->first();
        $this->assertSame('POHON:600001', $row($active)->target_key);
        $this->assertSame('600001', $row($active)->source_ref);
        $this->assertSame('ROW|RABAS|POHON:600001', $row($active)->active_target_key);
        $this->assertSame('VEGETASI:11', json_decode($row($active)->snapshot, true)['legacy_target_key']);

        $this->assertSame('POHON:600001', $row($clash)->target_key);
        $this->assertSame('ROW|RABAS|VEGETASI:12', $row($clash)->active_target_key, 'Kunci aktif bentrok tidak ditimpa.');
        $this->assertSame('ACTIVE_DUPLICATE_FOR_ID_POHON', json_decode($row($clash)->snapshot, true)['identity_conflict']);

        $this->assertSame('POHON:600002', $row($finished)->target_key);
        $this->assertNull($row($finished)->active_target_key);
        $this->assertSame('VEGETASI:14', $row($unknown)->target_key, 'Tanpa ID POHON di snapshot: dibiarkan.');
        $this->assertSame(4, WorkOrder::query()->count(), 'Tidak ada WO yang dibatalkan atau dihapus.');

        $migration->down();

        $after = DB::table('work_order_location_targets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $decode = fn (array $rows) => array_map(fn (array $r) => ['snapshot' => json_decode($r['snapshot'], true)] + $r, $rows);
        $this->assertEquals($decode($before), $decode($after));
    }

    private function createRow(int $vegetationId, string $workType): WorkOrder
    {
        $idPohon = DB::table('vegetasi_source_rows')->where('id', $vegetationId)->value('id_pohon_raw');
        $this->postRow($vegetationId, $workType, $idPohon)->assertSessionHasNoErrors();

        return WorkOrder::query()->latest('created_at')->latest('code')->firstOrFail();
    }

    private function postRow(int $vegetationId, string $workType, ?string $idPohon = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.team-work-orders.store', 'row'), array_filter([
            'work_type' => $workType,
            'penyulang_id' => $this->penyulang->id,
            'target_id' => $vegetationId,
            'target_ref' => $idPohon,
            'work_date' => '2026-10-28',
            'due_date' => '2026-10-30',
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function legacyTarget(string $key, ?string $activeKey, array $snapshot): string
    {
        static $sequence = 0;
        $sequence++;
        $wo = WorkOrder::create([
            'code' => 'WO-ROW-LAMA-'.$sequence, 'team_type' => WorkOrder::TEAM_ROW, 'work_type' => WorkOrder::TYPE_RABAS,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL, 'status' => $activeKey ? WorkOrderStatus::Ditugaskan : WorkOrderStatus::Selesai,
            'penyulang_id' => $this->penyulang->id, 'work_date' => '2026-10-20',
        ]);
        $id = (string) Str::uuid();
        DB::table('work_order_location_targets')->insert([
            'id' => $id, 'work_order_id' => $wo->id, 'target_type' => 'VEGETASI', 'source_ref' => substr($key, 9),
            'target_key' => $key, 'active_target_key' => $activeKey, 'label' => 'Pohon lama',
            'snapshot' => json_encode(['vegetasi_source_row_id' => (int) substr($key, 9)] + $snapshot),
            'created_at' => now()->addSeconds($sequence), 'updated_at' => now(),
        ]);

        return $id;
    }
}
