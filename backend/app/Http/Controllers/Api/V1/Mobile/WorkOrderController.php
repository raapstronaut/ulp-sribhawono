<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\WorkOrderIssue;
use App\Services\Work\FieldActor;
use App\Services\Work\FieldWorkScope;
use App\Services\Work\MediaStore;
use App\Services\Work\PoskoWorkOrderCrewReview;
use App\Services\Work\WorkOrderExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Work feed dan tindakan pelaksanaan WO untuk tim lapangan. WO di luar cakupan tim
 * dibalas 404 agar keberadaan WO tim lain tidak terungkap.
 */
class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderExecutionService $execution) {}

    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope') === 'history' ? 'history' : 'active';
        $query = FieldWorkScope::query($request->user())->with(['apkukTarget', 'locationTarget', 'penyulang', 'posko']);
        $scope === 'active'
            ? $query->active()->orderBy('due_at')->orderBy('code')
            : $query->whereNotIn('status', array_map(fn ($s) => $s->value, WorkOrderStatus::active()))->orderByDesc('updated_at');

        $page = $query->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (WorkOrder $wo) => $this->summary($wo))->all(),
            'meta' => [
                'scope' => $scope,
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function show(Request $request, string $workOrder, PoskoWorkOrderCrewReview $crewReview): JsonResponse
    {
        $wo = $this->scoped($request, $workOrder);
        $wo->load(['apkukTarget', 'locationTarget', 'penyulang', 'posko', 'reports.personnel', 'reschedules', 'escalations', 'issues']);

        $detail = $this->detail($wo);
        if ($wo->team_type === WorkOrder::TEAM_POSKO
            && in_array($wo->work_type, [WorkOrder::TYPE_P0, WorkOrder::TYPE_PEMASANGAN_APKUK], true)) {
            // Audit informatif dihitung setelah pemeriksaan scope; tidak mengubah POST/status WO.
            $detail['crew_audit'] = $crewReview->forWorkOrder($wo);
        }

        return $this->respond($detail);
    }

    public function start(Request $request, string $workOrder): JsonResponse
    {
        $wo = $this->execution->start($this->scoped($request, $workOrder), FieldActor::mobile($request->user()));

        return $this->respond($this->summary($wo->fresh(['apkukTarget', 'locationTarget', 'penyulang', 'posko'])));
    }

    public function report(Request $request, string $workOrder): JsonResponse
    {
        $wo = $this->scoped($request, $workOrder);
        $image = ['file', 'mimes:'.implode(',', MediaStore::MIMES), 'max:'.MediaStore::MAX_KILOBYTES];
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'report_type' => ['required', 'string', Rule::in(WorkOrderExecutionService::reportTypesFor($wo))],
            'installed_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'occurred_at' => ['nullable', 'date'],
            'personnel_ids' => ['nullable', 'array', 'max:20'],
            'personnel_ids.*' => ['uuid'],
            'personnel_names' => ['nullable', 'array', 'max:20'],
            'personnel_names.*' => ['string', 'max:255'],
            'photos_before' => ['nullable', 'array', 'max:10'],
            'photos_before.*' => $image,
            'photos_after' => ['nullable', 'array', 'max:10'],
            'photos_after.*' => $image,
            'photos_evidence' => ['nullable', 'array', 'max:10'],
            'photos_evidence.*' => $image,
        ]);

        $report = $this->execution->submitReport($wo, FieldActor::mobile($request->user()), $data, [
            'before' => $request->file('photos_before', []),
            'after' => $request->file('photos_after', []),
            'evidence' => $request->file('photos_evidence', []),
        ]);

        return $this->respond([
            'report_id' => $report->getKey(),
            'verification_status' => $report->verification_status,
            'work_order' => $this->summary($wo->fresh(['apkukTarget', 'locationTarget', 'penyulang', 'posko'])),
        ], 201, $report->wasRecentlyCreated);
    }

    public function reschedule(Request $request, string $workOrder): JsonResponse
    {
        $wo = $this->scoped($request, $workOrder);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'proposed_work_date' => ['required', 'date_format:Y-m-d'],
            'proposed_shift_code' => ['nullable', 'string', 'size:1'],
        ]);

        $reschedule = $this->execution->requestReschedule($wo, FieldActor::mobile($request->user()), $data);

        return $this->respond(['reschedule_id' => $reschedule->getKey(), 'status' => $reschedule->status], 201, $reschedule->wasRecentlyCreated);
    }

    public function escalate(Request $request, string $workOrder): JsonResponse
    {
        $wo = $this->scoped($request, $workOrder);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'target_team_type' => ['required', 'string', 'max:24'],
            'target_work_type' => ['required', 'string', 'max:40'],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $escalation = $this->execution->requestEscalation($wo, FieldActor::mobile($request->user()), $data);

        return $this->respond(['escalation_id' => $escalation->getKey(), 'status' => $escalation->status], 201, $escalation->wasRecentlyCreated);
    }

    public function issue(Request $request, string $workOrder): JsonResponse
    {
        $wo = $this->scoped($request, $workOrder);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'issue_type' => ['required', 'string', Rule::in(array_keys(WorkOrderIssue::TYPE_LABELS))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $issue = $this->execution->reportIssue($wo, FieldActor::mobile($request->user()), $data);

        return $this->respond(['issue_id' => $issue->getKey(), 'status' => $issue->status], 201, $issue->wasRecentlyCreated);
    }

    private function scoped(Request $request, string $id): WorkOrder
    {
        $wo = preg_match('/^[0-9a-f-]{36}$/i', $id) ? FieldWorkScope::find($request->user(), $id) : null;
        abort_if($wo === null, 404, 'WO tidak ditemukan.');

        return $wo;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool|null  $created  untuk pengiriman ber-client_request_id: false = replay record yang sudah ada
     */
    private function respond(array $data, int $status = 200, ?bool $created = null): JsonResponse
    {
        $meta = ['server_time' => now()->toIso8601String()];
        if ($created !== null) {
            $meta['idempotent_replay'] = ! $created;
        }

        return response()->json(['data' => $data, 'meta' => $meta], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WorkOrder $wo): array
    {
        $coordinates = $wo->targetCoordinates();

        return [
            'id' => $wo->getKey(),
            'code' => $wo->code,
            'team_type' => $wo->team_type,
            'work_type' => $wo->work_type,
            'source' => $wo->source,
            'status' => $wo->status->value,
            'status_label' => $wo->status->label(),
            'is_overdue' => $wo->isOverdue(),
            'work_date' => $wo->work_date?->toDateString(),
            'shift_code' => $wo->shift_code,
            'scheduled_start_at' => $wo->scheduled_start_at?->toIso8601String(),
            'due_at' => $wo->due_at?->toIso8601String(),
            'penyulang' => $wo->penyulang ? ['id' => $wo->penyulang->getKey(), 'name' => $wo->penyulang->nama_penyulang] : null,
            'posko' => $wo->posko ? ['id' => $wo->posko->getKey(), 'name' => $wo->posko->nama_posko] : null,
            'section_name' => $wo->section_name,
            'target' => [
                'label' => $wo->targetLabel(),
                'latitude' => $coordinates[0] ?? null,
                'longitude' => $coordinates[1] ?? null,
                // Navigasi: aplikasi membuka Google Maps eksternal dengan koordinat ini.
                'google_maps_url' => $coordinates ? 'https://www.google.com/maps/search/?api=1&query='.$coordinates[0].','.$coordinates[1] : null,
                'apkuk_target_quantity' => $wo->apkukTarget?->target_quantity,
            ],
            'report_types' => WorkOrderExecutionService::reportTypesFor($wo),
            'notes' => $wo->notes,
            // P0: keterkaitan dengan sesi PASILA dan sumber masalah (null untuk WO lain).
            'p0' => $wo->isP0() && ($p0 = $wo->loadMissing('p0Item.session:id,code')->p0Item)
                ? ['code' => $p0->code, 'pasila_session_code' => $p0->session->code, 'source_type' => $p0->source_type]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(WorkOrder $wo): array
    {
        return $this->summary($wo) + [
            'reports' => $wo->reports->map(fn ($r) => [
                'id' => $r->getKey(),
                'report_type' => $r->report_type,
                'verification_status' => $r->verification_status,
                'verification_note' => $r->verification_note,
                'installed_quantity' => $r->installed_quantity,
                'occurred_at' => $r->occurred_at?->toIso8601String(),
                'personnel' => $r->personnel->pluck('personnel_name')->all(),
            ])->all(),
            'reschedules' => $wo->reschedules->map(fn ($r) => [
                'id' => $r->getKey(),
                'status' => $r->status,
                'reason_code' => $r->reason_code,
                'proposed_work_date' => $r->proposed_work_date?->toDateString(),
                'proposed_shift_code' => $r->proposed_shift_code,
                'review_note' => $r->review_note,
            ])->all(),
            'escalations' => $wo->escalations->map(fn ($e) => [
                'id' => $e->getKey(),
                'status' => $e->status,
                'target_team_type' => $e->target_team_type,
                'target_work_type' => $e->target_work_type,
                'review_note' => $e->review_note,
            ])->all(),
            'issues' => $wo->issues->map(fn ($i) => [
                'id' => $i->getKey(),
                'issue_type' => $i->issue_type,
                'status' => $i->status,
                'resolution_note' => $i->resolution_note,
            ])->all(),
        ];
    }
}
