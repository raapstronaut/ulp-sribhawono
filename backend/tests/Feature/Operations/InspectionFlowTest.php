<?php

namespace Tests\Feature\Operations;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;

class InspectionFlowTest extends OperationsTestCase
{
    public function test_admin_records_no_finding_visit_honestly_and_it_is_not_counted_as_finding(): void
    {
        $person = $this->inspeksiTeam->personnel()->first();
        $base = ['field_team_id' => $this->inspeksiTeam->id, 'penyulang_id' => $this->penyulang->id, 'visited_at' => '2026-10-28T08:15', 'personnel_ids' => [$person->id]];

        $this->actingAs($this->admin)->post(route('admin.inspeksi.store'), $base)->assertSessionHasErrors('summary');
        $this->actingAs($this->admin)->post(route('admin.inspeksi.store'), $base + ['summary' => 'Ruas SEC 1 diperiksa, kondisi baik'])->assertSessionHasNoErrors();

        $inspection = Inspection::query()->sole();
        $this->assertFalse($inspection->has_finding);
        $this->assertSame('INS-20261028-0001', $inspection->code);
        $this->assertSame(0, InspectionFinding::query()->count());
        $this->actingAs($this->admin)->get(route('admin.inspeksi.show', $inspection))->assertOk()->assertSee('Kunjungan tanpa temuan.');
        $this->actingAs($this->admin)->get(route('admin.inspeksi.index'))->assertOk()->assertSee('Tidak ada temuan');
    }

    public function test_finding_validation_with_follow_up_row_work_order_and_rejection(): void
    {
        $person = $this->inspeksiTeam->personnel()->first();
        $keypoint = $this->keypoint(['nama' => 'LBS REOG 2', 'jenis' => 'LBS']);
        $this->actingAs($this->admin)->post(route('admin.inspeksi.store'), [
            'field_team_id' => $this->inspeksiTeam->id,
            'penyulang_id' => $this->penyulang->id,
            'visited_at' => '2026-10-28T08:15',
            'personnel_ids' => [$person->id],
            'findings' => [
                ['finding_code' => 'POHON_DEKAT', 'description' => 'Bambu menyentuh kabel', 'latitude' => '-5.25', 'longitude' => '105.75', 'suggested_team_type' => 'ROW', 'photos' => [$this->photo()]],
                ['finding_code' => 'POHON_DEKAT', 'description' => 'Ranting dekat LBS', 'keypoint_id' => $keypoint],
                ['finding_code' => '', 'description' => '', 'latitude' => '', 'longitude' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $inspection = Inspection::query()->sole();
        $this->assertTrue($inspection->has_finding);
        [$withCoordinates, $withAsset] = $inspection->findings()->get()->all();
        $this->assertCount(2, $inspection->findings);
        $this->assertCount(1, $withCoordinates->media);
        $this->assertSame('LBS LBS REOG 2', $withAsset->asset_label);
        $this->assertSame(InspectionFinding::VALIDATION_MENUNGGU, $withCoordinates->validation_status);
        $this->actingAs($this->admin)->get(route('admin.reviews.index', ['category' => 'findings']))->assertOk()->assertSee('Bambu menyentuh kabel');

        // ACC + WO tindak lanjut ROW pada lokasi temuan.
        $this->actingAs($this->admin)->post(route('admin.inspeksi.findings.approve', $withCoordinates), [
            'follow_up' => 'ROW|RABAS', 'field_team_id' => $this->rowTeam->id, 'work_date' => '2026-10-28', 'due_date' => '2026-10-29',
        ])->assertSessionHasNoErrors();
        $withCoordinates->refresh();
        $this->assertSame(InspectionFinding::VALIDATION_DISETUJUI, $withCoordinates->validation_status);
        $wo = WorkOrder::query()->findOrFail($withCoordinates->follow_up_work_order_id);
        $this->assertSame(WorkOrder::TYPE_RABAS, $wo->work_type);
        $this->assertSame(WorkOrderLocationTarget::TYPE_INSPEKSI, $wo->locationTarget->target_type);
        $this->assertSame('-5.2500000', (string) $wo->locationTarget->latitude);

        // Tolak wajib alasan; temuan ditolak tidak membuat WO.
        $this->actingAs($this->admin)->post(route('admin.inspeksi.findings.reject', $withAsset), ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->actingAs($this->admin)->post(route('admin.inspeksi.findings.reject', $withAsset), ['review_note' => 'Sudah ditangani bulan lalu'])->assertSessionHasNoErrors();
        $this->assertSame(InspectionFinding::VALIDATION_DITOLAK, $withAsset->refresh()->validation_status);
        $this->assertNull($withAsset->follow_up_work_order_id);
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(2, AuditLog::query()->where('entity_type', 'inspection_finding')->count());

        // Keputusan ganda ditolak.
        $this->actingAs($this->admin)->post(route('admin.inspeksi.findings.approve', $withAsset))->assertSessionHasErrors('workflow');
    }

    public function test_harsus_follow_up_requires_network_asset(): void
    {
        $person = $this->inspeksiTeam->personnel()->first();
        $this->actingAs($this->admin)->post(route('admin.inspeksi.store'), [
            'field_team_id' => $this->inspeksiTeam->id, 'penyulang_id' => $this->penyulang->id, 'visited_at' => '2026-10-28T08:15', 'personnel_ids' => [$person->id],
            'findings' => [['finding_code' => 'POHON_DEKAT', 'description' => 'Tiang retak', 'latitude' => '-5.25', 'longitude' => '105.75']],
        ])->assertSessionHasNoErrors();
        $finding = InspectionFinding::query()->sole();

        $this->actingAs($this->admin)->post(route('admin.inspeksi.findings.approve', $finding), [
            'follow_up' => 'HARSUS|PEKERJAAN_KHUSUS', 'work_date' => '2026-10-28', 'due_date' => '2026-10-29',
        ])->assertSessionHasErrors('follow_up_team_type');
        $this->assertSame(InspectionFinding::VALIDATION_MENUNGGU, $finding->refresh()->validation_status);
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_mobile_inspection_requires_photo_per_finding_and_is_idempotent(): void
    {
        $account = $this->fieldAccount($this->inspeksiTeam, 'inspeksi_hp');
        $person = $this->inspeksiTeam->personnel()->first();
        $this->actingAs($account, 'sanctum');
        $payload = [
            'client_request_id' => 'ins-1',
            'penyulang_id' => $this->penyulang->id,
            'visited_at' => '2026-10-28 08:00:00',
            'latitude' => -5.2, 'longitude' => 105.7,
            'personnel_ids' => [$person->id],
            'findings' => [['finding_code' => 'POHON_DEKAT', 'description' => 'Pohon condong', 'latitude' => -5.21, 'longitude' => 105.71]],
        ];

        $this->post('/api/v1/mobile/inspections', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('findings.0.photos');
        $payload['findings'][0]['photos'] = [$this->photo()];
        $this->post('/api/v1/mobile/inspections', $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.findings.0.validation_status', 'MENUNGGU_VALIDASI');
        $this->post('/api/v1/mobile/inspections', $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, Inspection::query()->count());
        $this->assertSame(Inspection::SOURCE_MOBILE, Inspection::query()->value('source'));

        // Akun POSKO tidak boleh mencatat inspeksi.
        $posko = $this->fieldAccount($this->poskoTeam, 'posko_bukan_inspeksi');
        auth()->forgetGuards();
        $this->actingAs($posko, 'sanctum');
        $this->postJson('/api/v1/mobile/inspections', ['client_request_id' => 'x'])->assertForbidden();
    }
}
