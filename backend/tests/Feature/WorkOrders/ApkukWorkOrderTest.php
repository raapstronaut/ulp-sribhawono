<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\Apkuk;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\ApkukCandidateService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ApkukWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Posko $posko;

    private Penyulang $penyulang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // 09:00 WIB → shift A (08–16) on 20 Oct 2026.
        $this->travelTo(Carbon::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta'));

        $this->admin = User::factory()->admin()->create(['username' => 'WO_ADMIN']);
        $this->posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        Posko::create(['kode_posko' => 'JABUNG', 'nama_posko' => 'JABUNG']);
        $this->penyulang = Penyulang::create(['kode_penyulang' => 'REOG', 'nama_penyulang' => 'REOG']);
    }

    public function test_admin_can_open_list_and_create_pages(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.001096', 'kurang_bh' => 4]);

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.index'))
            ->assertOk()
            ->assertSee('Work Order APKUK')
            ->assertSee('Belum ada Work Order.');

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.create'))
            ->assertOk()
            ->assertSee('F.REOG.001096')
            ->assertSee(route('admin.work-orders.create', ['apkuk_id' => $id]), false);

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.create', ['apkuk_id' => $id]))
            ->assertOk()
            ->assertSee('name="posko_id"', false)
            ->assertSee('— Pilih POSKO —');
    }

    public function test_admin_creates_manual_apkuk_work_order_and_sees_detail(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.001096', 'kurang_bh' => 4, 'terpasang_bh' => 1, 'section' => 'REC BRAJA DEWA']);

        $response = $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id, [
            'notes' => 'Prioritas jalur utama',
        ]));

        $wo = WorkOrder::with('apkukTarget')->sole();
        $response->assertRedirect(route('admin.work-orders.show', $wo));

        $this->assertSame('WO-APKUK-20261020-0001', $wo->code);
        $this->assertSame(WorkOrder::TEAM_POSKO, $wo->team_type);
        $this->assertSame(WorkOrder::TYPE_PEMASANGAN_APKUK, $wo->work_type);
        $this->assertSame(WorkOrder::SOURCE_ADMIN_MANUAL, $wo->source);
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->status);
        $this->assertSame($this->posko->id, $wo->posko_id);
        $this->assertSame($this->penyulang->id, $wo->penyulang_id);
        $this->assertSame('REC BRAJA DEWA', $wo->section_name);
        $this->assertSame('2026-10-20', $wo->work_date->toDateString());
        $this->assertSame('A', $wo->shift_code);
        // Shift A 08:00–16:00 WIB = 01:00–09:00 UTC.
        $this->assertSame('2026-10-20 01:00:00', $wo->scheduled_start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 09:00:00', $wo->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->admin->id, $wo->created_by_user_id);
        $this->assertSame('Prioritas jalur utama', $wo->notes);

        $target = $wo->apkukTarget;
        $this->assertSame($id, $target->apkuk_id);
        $this->assertSame(4, $target->target_quantity);
        $this->assertSame(4, $target->source_kurang_bh);
        $this->assertSame(1, $target->source_terpasang_bh);
        $this->assertSame('REOG|F.REOG.001096', $target->active_target_key);
        $this->assertNull($target->installed_quantity);

        $log = WorkOrderStatusLog::sole();
        $this->assertSame(WorkOrderStatusLog::ACTION_CREATED, $log->action);
        $this->assertSame('DITUGASKAN', $log->to_status);
        $this->assertSame($this->admin->id, $log->actor_user_id);
        $this->assertSame($id, $log->payload['apkuk_id']);

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('WO-APKUK-20261020-0001')
            ->assertSee('WO APKUK WO-APKUK-20261020-0001 berhasil dibuat.')
            ->assertSee('F.REOG.001096')
            ->assertSee('PUGUNG')
            ->assertSee('Ditugaskan');

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.index'))
            ->assertOk()
            ->assertSee('WO-APKUK-20261020-0001');
    }

    public function test_creating_work_order_does_not_modify_apkuk_history(): void
    {
        $id = $this->apkuk(['kurang_bh' => 3]);
        $before = (array) DB::table('apkuks')->where('id', $id)->first();

        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect();

        $this->assertSame($before, (array) DB::table('apkuks')->where('id', $id)->first());
        $this->assertSame(1, DB::table('apkuks')->count());
    }

    public function test_second_active_work_order_for_same_pole_is_rejected(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.000500', 'kurang_bh' => 2]);

        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect();
        $this->actingAs($this->admin)
            ->from(route('admin.work-orders.create', ['apkuk_id' => $id]))
            ->post(route('admin.work-orders.store'), $this->payload($id, ['shift_code' => 'B']))
            ->assertRedirect(route('admin.work-orders.create', ['apkuk_id' => $id]))
            ->assertSessionHasErrors('apkuk_id');

        $this->assertSame(1, WorkOrder::count());
        $this->assertStringContainsString('WO-APKUK-20261020-0001', session('errors')->first('apkuk_id'));
    }

    public function test_newer_report_for_pole_with_active_work_order_is_blocked(): void
    {
        $first = $this->apkuk(['no_tiang' => 'F.REOG.000600', 'tanggal' => '2026-03-01', 'kurang_bh' => 2]);
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($first))->assertRedirect();

        // Sync later adds a newer report for the same pole.
        $newer = $this->apkuk(['no_tiang' => 'F.REOG.000600', 'tanggal' => '2026-04-01', 'kurang_bh' => 5]);

        $candidate = app(ApkukCandidateService::class)->find($newer);
        $this->assertSame([ApkukCandidateService::ISSUE_WO_AKTIF], $candidate->issues);
        $this->assertSame('WO-APKUK-20261020-0001', $candidate->activeWorkOrderCode);

        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($newer))
            ->assertSessionHasErrors('apkuk_id');
        $this->assertSame(1, WorkOrder::count());
    }

    public function test_database_unique_index_blocks_concurrent_duplicate_active_target(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.000700']);
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect();
        $existing = WorkOrderApkukTarget::sole();

        $other = WorkOrder::create([
            'code' => 'WO-RACE-0001',
            'team_type' => WorkOrder::TEAM_POSKO,
            'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL,
            'status' => WorkOrderStatus::Ditugaskan,
            'work_date' => '2026-10-20',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        WorkOrderApkukTarget::create([
            'work_order_id' => $other->id,
            'apkuk_id' => $id,
            'target_key' => $existing->target_key,
            'active_target_key' => $existing->target_key,
            'no_tiang' => 'F.REOG.000700',
            'source_kurang_bh' => 2,
            'target_quantity' => 2,
        ]);
    }

    public function test_cancelled_work_order_releases_pole_for_new_work_order(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.000800']);
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect();
        $wo = WorkOrder::sole();

        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => ''])
            ->assertSessionHasErrors('cancel_reason');
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Salah pilih POSKO'])
            ->assertRedirect(route('admin.work-orders.show', $wo));

        $wo->refresh();
        $this->assertSame(WorkOrderStatus::Dibatalkan, $wo->status);
        $this->assertSame('Salah pilih POSKO', $wo->cancel_reason);
        $this->assertSame($this->admin->id, $wo->cancelled_by_user_id);
        $this->assertNull($wo->apkukTarget->active_target_key);
        $this->assertSame('REOG|F.REOG.000800', $wo->apkukTarget->target_key);

        $cancelLog = WorkOrderStatusLog::where('action', WorkOrderStatusLog::ACTION_CANCELLED)->sole();
        $this->assertSame('DITUGASKAN', $cancelLog->from_status);
        $this->assertSame('DIBATALKAN', $cancelLog->to_status);
        $this->assertSame('Salah pilih POSKO', $cancelLog->reason);

        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect();
        $this->assertSame(2, WorkOrder::count());
        $this->assertSame('WO-APKUK-20261020-0002', WorkOrder::where('status', WorkOrderStatus::Ditugaskan->value)->sole()->code);

        // A cancelled WO cannot be cancelled again.
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.cancel', $wo), ['cancel_reason' => 'Batal lagi'])
            ->assertSessionHasErrors('status');
    }

    public function test_invalid_candidate_cannot_be_submitted(): void
    {
        $zero = $this->apkuk(['no_tiang' => 'F.REOG.000900', 'kurang_bh' => 0, 'status' => 'SELESAI']);
        $contradictory = $this->apkuk(['no_tiang' => 'F.REOG.000901', 'kurang_bh' => 3, 'status' => 'SELESAI']);

        foreach ([$zero, $contradictory] as $id) {
            $this->actingAs($this->admin)
                ->post(route('admin.work-orders.store'), $this->payload($id))
                ->assertSessionHasErrors('apkuk_id');
        }

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_posko_must_be_chosen_explicitly_and_active(): void
    {
        $id = $this->apkuk();
        $inactive = Posko::create(['kode_posko' => 'TUTUP', 'nama_posko' => 'TUTUP', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($id, ['posko_id' => '']))
            ->assertSessionHasErrors('posko_id');
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($id, ['posko_id' => $inactive->id]))
            ->assertSessionHasErrors('posko_id');

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_schedule_must_not_be_in_the_past(): void
    {
        $id = $this->apkuk();

        // Hari operasional 20 Okt pukul 17:00 WIB: Shift A (08–16) sudah berakhir.
        $this->travelTo(Carbon::create(2026, 10, 20, 17, 0, 0, 'Asia/Jakarta'));
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($id, ['shift_code' => 'A']))
            ->assertSessionHasErrors('shift_code');
        $this->travelTo(Carbon::create(2026, 10, 20, 9, 0, 0, 'Asia/Jakarta'));
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($id, ['work_date' => '2026-10-19']))
            ->assertSessionHasErrors('work_date');
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($id, ['shift_code' => 'X']))
            ->assertSessionHasErrors('shift_code');

        $this->assertSame(0, WorkOrder::count());

        // Shift C hari operasional 20 Okt = 21 Okt 00:00–08:00 WIB (keputusan 29 Okt 2026).
        $this->actingAs($this->admin)
            ->post(route('admin.work-orders.store'), $this->payload($id, ['work_date' => '2026-10-20', 'shift_code' => 'C']))
            ->assertRedirect();
        $this->assertSame('2026-10-21 01:00:00', WorkOrder::sole()->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_active_work_order_past_its_shift_is_overdue(): void
    {
        $id = $this->apkuk(['no_tiang' => 'F.REOG.001000']);
        $this->actingAs($this->admin)->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect();
        $wo = WorkOrder::sole();

        $this->assertFalse($wo->isOverdue());
        $this->assertSame(0, WorkOrder::query()->overdue()->count());

        $this->travelTo(Carbon::create(2026, 10, 20, 16, 30, 0, 'Asia/Jakarta'));

        $this->assertTrue($wo->fresh()->isOverdue());
        $this->assertSame(1, WorkOrder::query()->overdue()->count());
        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.index', ['status' => 'TERLAMBAT']))
            ->assertOk()
            ->assertSee($wo->code);
    }

    public function test_non_admin_cannot_create_or_cancel_work_orders(): void
    {
        $id = $this->apkuk();
        $posko = User::factory()->create(['username' => 'POSKO_PUGUNG', 'role' => 'POSKO', 'is_active' => true]);

        $this->post(route('admin.work-orders.store'), $this->payload($id))->assertRedirect(route('login'));
        $this->actingAs($posko)->get(route('admin.work-orders.create'))->assertForbidden();
        $this->actingAs($posko)->post(route('admin.work-orders.store'), $this->payload($id))->assertForbidden();

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_unknown_work_order_returns_not_found(): void
    {
        $this->actingAs($this->admin)->get('/admin/work-orders/example')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/work-orders/'.'9f1c1a7e-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_apkuk_model_is_read_only(): void
    {
        $apkuk = Apkuk::findOrFail($this->apkuk());
        $apkuk->kurang_bh = 99;

        $this->expectException(LogicException::class);
        $apkuk->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $apkukId, array $overrides = []): array
    {
        return array_merge([
            'apkuk_id' => $apkukId,
            'posko_id' => $this->posko->id,
            'work_date' => '2026-10-20',
            'shift_code' => 'A',
        ], $overrides);
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
            'no_tiang' => 'F.REOG.9'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
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
