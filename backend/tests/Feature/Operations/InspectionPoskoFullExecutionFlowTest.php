<?php

namespace Tests\Feature\Operations;

use App\Enums\WorkOrderStatus;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderMedia;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderStatusLog;
use App\Services\ShiftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Integration-only regression tests for INSPEKSI FULL -> manual POSKO APKUK WO
 * -> mobile execution -> evidence -> completion. Uses OperationsTestCase's
 * in-memory SQLite and fake local storage; never runs the shift generator.
 */
class InspectionPoskoFullExecutionFlowTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 22, 10, 0, 0, ShiftService::TIMEZONE));
    }

    public function test_approved_full_finding_wo_completes_via_posko_mobile_with_photos_and_traceable_origin(): void
    {
        [$finding, $wo] = $this->createApprovedInspectionPoskoWo();

        $this->assertNull($wo->apkukTarget->apkuk_id);
        $this->assertSame($finding->getKey(), $wo->apkukTarget->inspection_finding_id);
        $this->assertSame(0, DB::table('apkuks')->count(), 'An inspection-derived WO must not manufacture SPS data.');

        $account = $this->fieldAccount($this->poskoTeam, 'posko_full_v5');
        $this->app['auth']->forgetGuards();
        $this->actingAs($account, 'sanctum');

        $this->getJson('/api/v1/mobile/work-feed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $wo->getKey())
            ->assertJsonPath('data.0.status', WorkOrderStatus::Ditugaskan->value)
            ->assertJsonPath('data.0.target.label', 'F.REOG.123')
            ->assertJsonPath('data.0.target.apkuk_target_quantity', 2);

        $this->getJson('/api/v1/mobile/work-orders/'.$wo->getKey())
            ->assertOk()
            ->assertJsonPath('data.report_types', [
                WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
                WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP,
            ]);

        $this->postJson('/api/v1/mobile/work-orders/'.$wo->getKey().'/start')
            ->assertOk()
            ->assertJsonPath('data.status', WorkOrderStatus::Dikerjakan->value);

        $photos = [
            'photos_before' => [$this->photo('v5-before.jpg')],
            'photos_after' => [$this->photo('v5-after.jpg')],
        ];
        $payload = [
            'client_request_id' => 'v5-inspection-posko-001',
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'installed_quantity' => 1,
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'personnel_ids' => [$this->poskoPerson->getKey()],
            'notes' => 'Terpasang satu dari dua unit yang diperlukan',
        ];

        $first = $this->post('/api/v1/mobile/work-orders/'.$wo->getKey().'/reports',
            $payload + $photos, ['Accept' => 'application/json']);
        $first->assertCreated()
            ->assertJsonPath('data.verification_status', WorkOrderReport::VERIFICATION_TIDAK_PERLU)
            ->assertJsonPath('data.work_order.status', WorkOrderStatus::Selesai->value)
            ->assertJsonPath('meta.idempotent_replay', false);

        // Offline retry of the identical request must not duplicate the report or its media.
        $this->post('/api/v1/mobile/work-orders/'.$wo->getKey().'/reports',
            $payload + $photos, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.report_id', $first->json('data.report_id'))
            ->assertJsonPath('meta.idempotent_replay', true);

        $wo->refresh();
        $target = $wo->apkukTarget->fresh();
        $this->assertSame(WorkOrderStatus::Selesai, $wo->status);
        $this->assertNotNull($wo->completed_at);
        $this->assertNull($target->apkuk_id);
        $this->assertSame($finding->getKey(), $target->inspection_finding_id);
        $this->assertSame(2, $target->target_quantity);
        $this->assertSame(1, $target->installed_quantity);
        $this->assertSame(1, $target->remaining_quantity);
        $this->assertNull($target->active_target_key);
        $this->assertSame($wo->getKey(), $finding->fresh()->follow_up_work_order_id);
        $this->assertSame(0, DB::table('apkuks')->count());

        $this->assertSame(1, WorkOrderReport::query()->where('work_order_id', $wo->getKey())->count());
        $this->assertSame(2, WorkOrderMedia::query()->where('work_order_id', $wo->getKey())->count());
        $this->assertEqualsCanonicalizing(
            [WorkOrderMedia::PHOTO_BEFORE, WorkOrderMedia::PHOTO_AFTER],
            $wo->media()->pluck('photo_type')->all()
        );
        foreach ($wo->media as $media) {
            Storage::disk('local')->assertExists($media->storage_path);
        }

        $this->assertSame(1, $wo->statusLogs()->where('action', WorkOrderStatusLog::ACTION_STARTED)->count());
        $this->assertSame(1, $wo->statusLogs()->where('action', WorkOrderStatusLog::ACTION_REPORT_SUBMITTED)->count());
        $this->assertSame($finding->getKey(), $wo->statusLogs()->where('action', WorkOrderStatusLog::ACTION_CREATED)->sole()->payload['inspection_finding_id']);
        $this->assertSame('INSPEKSI', $wo->statusLogs()->where('action', WorkOrderStatusLog::ACTION_CREATED)->sole()->payload['origin']);

        $this->getJson('/api/v1/mobile/work-feed?scope=history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $wo->getKey())
            ->assertJsonPath('data.0.status', WorkOrderStatus::Selesai->value);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('Pemasangan selesai')
            ->assertSee('Lihat inspeksi sumber')
            ->assertSee(route('admin.inspeksi.show', $finding->inspection_id), false)
            ->assertDontSee('Lihat laporan APKUK sumber');
    }

    public function test_sps_legacy_work_order_keeps_its_existing_apkuk_source_link(): void
    {
        // The legacy test helper schedules its WO on 2026-10-28, not the FULL fixture date.
        $this->travelTo(Carbon::create(2026, 10, 28, 9, 0, 0, ShiftService::TIMEZONE));

        $apkukId = $this->apkuk();
        $wo = $this->createApkukWorkOrder($apkukId);

        $this->actingAs($this->admin)
            ->get(route('admin.work-orders.show', $wo))
            ->assertOk()
            ->assertSee('Lihat laporan APKUK sumber')
            ->assertSee(route('admin.apkuk.show', $apkukId), false)
            ->assertDontSee('Lihat inspeksi sumber');
    }

    public function test_invalid_mobile_completion_creates_no_report_and_can_be_corrected_with_same_request_id(): void
    {
        [, $wo] = $this->createApprovedInspectionPoskoWo();
        $account = $this->fieldAccount($this->poskoTeam, 'posko_full_invalid_v5');
        $this->app['auth']->forgetGuards();
        $this->actingAs($account, 'sanctum');

        $payload = [
            'client_request_id' => 'v5-inspection-posko-corrected',
            'report_type' => WorkOrderReport::TYPE_PEMASANGAN_SELESAI,
            'installed_quantity' => 2,
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'personnel_ids' => [$this->poskoPerson->getKey()],
        ];

        $this->post('/api/v1/mobile/work-orders/'.$wo->getKey().'/reports',
            $payload + ['photos_before' => [$this->photo('only-before.jpg')]],
            ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos');

        $this->assertSame(0, $wo->reports()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(WorkOrderStatus::Ditugaskan, $wo->fresh()->status);

        $this->post('/api/v1/mobile/work-orders/'.$wo->getKey().'/reports',
            $payload + [
                'photos_before' => [$this->photo('correct-before.jpg')],
                'photos_after' => [$this->photo('correct-after.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonPath('data.work_order.status', WorkOrderStatus::Selesai->value);

        $this->assertSame(1, $wo->reports()->count());
        $this->assertSame(2, $wo->media()->count());
        $this->assertSame(2, $wo->apkukTarget->fresh()->installed_quantity);
        $this->assertSame(0, $wo->apkukTarget->fresh()->remaining_quantity);
    }

    /** @return array{0: InspectionFinding, 1: WorkOrder} */
    private function createApprovedInspectionPoskoWo(): array
    {
        $inspection = Inspection::create([
            'code' => 'INS-V5-0001',
            'penyulang_id' => $this->penyulang->getKey(),
            'section_name' => 'SEC PUGUNG',
            'visited_at' => now(),
            'has_finding' => true,
            'source' => Inspection::SOURCE_ADMIN,
            'inspection_type' => Inspection::TYPE_REGULAR,
            'submitted_by_user_id' => $this->admin->getKey(),
        ]);
        $finding = InspectionFinding::create([
            'inspection_id' => $inspection->getKey(),
            'finding_code' => 'APKUK_UJI_V5',
            'description' => 'Kurang dua set APKUK pada tiang uji V5',
            'asset_label' => 'Tiang F.REOG.123',
            'latitude' => -5.1234567,
            'longitude' => 105.1234567,
            'suggested_team_type' => WorkOrder::TEAM_POSKO,
            'apkuk_no_tiang' => 'F.REOG.123',
            'apkuk_target_quantity' => 2,
            'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
            'validated_by_user_id' => $this->admin->getKey(),
            'validated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.inspeksi.findings.posko-work-order', $finding), [
                'posko_id' => $this->posko->getKey(),
                'field_team_id' => $this->poskoTeam->getKey(),
                'work_date' => '2026-11-23',
                'shift_code' => 'A',
            ])
            ->assertRedirect(route('admin.inspeksi.show', $inspection))
            ->assertSessionHasNoErrors();

        $woId = $finding->fresh()->follow_up_work_order_id;
        $this->assertNotNull($woId, 'Admin must create a real WO linked to the approved finding.');
        $wo = WorkOrder::query()->findOrFail($woId);
        $this->assertSame(WorkOrder::SOURCE_ADMIN_MANUAL, $wo->source);
        $this->assertNull($wo->generation_run_id);
        $this->assertSame($this->poskoTeam->getKey(), $wo->field_team_id);

        return [$finding, $wo];
    }
}
