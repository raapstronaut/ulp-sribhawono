<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\PasilaFinding;
use App\Models\PasilaNilReport;
use App\Models\PasilaSession;
use App\Models\ReferenceCode;
use App\Models\WorkOrder;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Pasila\PasilaFindingService;
use App\Services\ShiftService;
use App\Services\Work\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sesi PASILA dan temuan per titik untuk akun tim POSKO (Batch B3.2).
 *
 * Sesi dibuat server pada awal shift (PasilaShiftOpener); aplikasi tidak membuat sesi sendiri.
 * Akun non-POSKO → 403; sesi Posko lain → 404. Satu temuan per request (multipart, foto per
 * temuan); laporan nihil terpisah dari temuan. Pengiriman ulang dengan client_request_id sama
 * mengembalikan record yang sudah ada (meta.idempotent_replay).
 */
class PasilaController extends Controller
{
    public function index(Request $request, ShiftService $shifts): JsonResponse
    {
        $team = $this->poskoTeam($request);
        $current = $shifts->instanceAt();
        $page = PasilaSession::query()
            ->where('posko_id', $team->posko_id)
            ->join('shift_instances as s', 's.id', '=', 'pasila_sessions.shift_instance_id')
            ->select('pasila_sessions.*')
            ->with(['shiftInstance', 'penyulang', 'nilReport', 'closure'])
            ->withCount('findings')
            ->orderByDesc('s.starts_at')
            ->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (PasilaSession $session) => $this->sessionSummary($session, $current))->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'server_time' => now()->toIso8601String()],
        ]);
    }

    public function show(Request $request, PasilaSession $pasilaSession, ShiftService $shifts): JsonResponse
    {
        $team = $this->poskoTeam($request);
        $session = $this->scoped($pasilaSession, $team)->load(['shiftInstance', 'penyulang', 'nilReport.personnel', 'closure'])->loadCount('findings');
        $findings = $session->findings()->with(['personnel', 'media'])->orderBy('sequence')->get();

        return response()->json([
            'data' => $this->sessionSummary($session, $shifts->instanceAt()) + [
                // Pilihan Section temuan: hanya Section terverifikasi milik Posko ini (bisa beberapa penyulang).
                'sections' => array_map(fn (array $entry) => ['section' => $entry['section'], 'penyulang' => $entry['penyulang']], SectionPoskoMap::load()->sectionsForPosko($team->posko_id)),
                'finding_codes' => ReferenceCode::query()->activeFor(ReferenceCode::CATEGORY_PASILA_FINDING, WorkOrder::TEAM_POSKO)->get(['code', 'label'])
                    ->map(fn (ReferenceCode $code) => ['code' => $code->code, 'label' => $code->label])->all(),
                'findings' => $findings->map(fn (PasilaFinding $finding) => $this->findingData($finding))->all(),
                'nil_report' => $session->nilReport ? $this->nilData($session->nilReport) : null,
            ],
            'meta' => ['server_time' => now()->toIso8601String()],
        ]);
    }

    public function storeFinding(Request $request, PasilaSession $pasilaSession, PasilaFindingService $service): JsonResponse
    {
        $team = $this->poskoTeam($request);
        $session = $this->scoped($pasilaSession, $team);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'finding_code' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
            'section' => ['nullable', 'string', 'max:255'],
            'keypoint_id' => ['nullable', 'integer'],
            'asset_label' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'occurred_at' => ['required', 'date'],
            'personnel_ids' => ['required', 'array', 'min:1', 'max:20'],
            'personnel_ids.*' => ['uuid'],
            'photos' => ['required', 'array', 'min:1', 'max:'.PasilaFindingService::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:'.implode(',', MediaStore::MIMES), 'max:'.MediaStore::MAX_KILOBYTES],
        ]);
        $this->assertGps($data);

        $finding = $service->recordFinding($session, $request->user(), $data, $request->file('photos', []));
        $finding->load(['personnel', 'media']);

        return response()->json([
            'data' => $this->findingData($finding),
            'meta' => ['server_time' => now()->toIso8601String(), 'idempotent_replay' => ! $finding->wasRecentlyCreated],
        ], 201);
    }

    public function storeNilReport(Request $request, PasilaSession $pasilaSession, PasilaFindingService $service): JsonResponse
    {
        $team = $this->poskoTeam($request);
        $session = $this->scoped($pasilaSession, $team);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'note' => ['required', 'string', 'max:2000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'occurred_at' => ['required', 'date'],
            'personnel_ids' => ['required', 'array', 'min:1', 'max:20'],
            'personnel_ids.*' => ['uuid'],
        ]);
        $this->assertGps($data);

        $report = $service->recordNilReport($session, $request->user(), $data);
        $report->load('personnel');

        return response()->json([
            'data' => $this->nilData($report),
            'meta' => ['server_time' => now()->toIso8601String(), 'idempotent_replay' => ! $report->wasRecentlyCreated],
        ], 201);
    }

    public function close(Request $request, PasilaSession $pasilaSession, PasilaFindingService $service, ShiftService $shifts): JsonResponse
    {
        $team = $this->poskoTeam($request);
        $session = $this->scoped($pasilaSession, $team);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
            'personnel_ids' => ['required', 'array', 'min:1', 'max:20'],
            'personnel_ids.*' => ['uuid'],
            'note' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $closure = $service->close($session, $request->user(), $data);
        $session = $session->fresh(['shiftInstance', 'penyulang', 'nilReport', 'closure'])->loadCount('findings');
        $snapshot = $closure->snapshot;
        // Peringatan tidak memblokir penutupan; kewajiban tetap berjalan terpisah.
        $warnings = array_filter([
            'P0_TARGET_BELUM_TERPENUHI' => ($snapshot['p0']['fulfilled'] ?? false) ? null : 'Target P0 shift belum terpenuhi; status tetap tercatat belum terpenuhi.',
            'APKUK_RESCHEDULE_MENUNGGU_ACC' => ($snapshot['apkuk']['reschedule_menunggu_acc'] ?? 0) > 0 ? $snapshot['apkuk']['reschedule_menunggu_acc'].' WO APKUK menunggu ACC reschedule; kewajiban WO tetap berjalan.' : null,
            'APKUK_BELUM_SELESAI' => ($snapshot['apkuk']['belum_selesai'] ?? 0) > 0 ? $snapshot['apkuk']['belum_selesai'].' WO APKUK belum selesai.' : null,
        ]);

        return response()->json([
            'data' => $this->sessionSummary($session, $shifts->instanceAt()),
            'meta' => ['server_time' => now()->toIso8601String(), 'idempotent_replay' => ! $closure->wasRecentlyCreated, 'warnings' => $warnings],
        ], 201);
    }

    private function poskoTeam(Request $request): FieldTeam
    {
        $team = $request->user()->fieldTeam;
        abort_unless($team !== null && $team->role === WorkOrder::TEAM_POSKO && $team->posko_id !== null, 403, 'Hanya akun tim POSKO yang dapat memakai PASILA.');

        return $team;
    }

    private function scoped(PasilaSession $session, FieldTeam $team): PasilaSession
    {
        abort_unless($session->posko_id === $team->posko_id, 404, 'Sesi PASILA tidak ditemukan untuk Posko tim ini.');

        return $session;
    }

    /**
     * Koordinat 0,0 adalah nilai bawaan GPS yang gagal, bukan lokasi temuan.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertGps(array $data): void
    {
        if ((float) $data['latitude'] === 0.0 && (float) $data['longitude'] === 0.0) {
            throw ValidationException::withMessages(['latitude' => 'GPS perangkat belum terkunci (0,0). Ulangi saat lokasi tersedia.']);
        }
    }

    /**
     * @param  array{operational_date: string, code: string}  $current
     * @return array<string, mixed>
     */
    private function sessionSummary(PasilaSession $session, array $current): array
    {
        $shift = $session->shiftInstance;

        return [
            'id' => $session->getKey(),
            'code' => $session->code,
            'operational_date' => $shift->operational_date->toDateString(),
            'shift_code' => $shift->shift_code,
            'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at->toIso8601String(),
            'is_current' => $shift->operational_date->toDateString() === $current['operational_date'] && $shift->shift_code === $current['code'],
            'status' => $session->status,
            'section_status' => $session->section_status,
            'section' => $session->hasSection() ? ['section' => $session->section_name, 'penyulang' => $session->penyulang?->kode_penyulang] : null,
            'patrol_status' => $session->patrolStatus(),
            'finding_count' => (int) ($session->findings_count ?? $session->findings()->count()),
            'closure' => ($closure = $session->relationLoaded('closure') ? $session->closure : $session->closure()->first()) ? [
                'outcome' => $closure->outcome,
                'occurred_at' => $closure->occurred_at->toIso8601String(),
                'received_at' => $closure->received_at->toIso8601String(),
                'flags' => array_keys($session->closureFlags()),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findingData(PasilaFinding $finding): array
    {
        return [
            'id' => $finding->getKey(),
            'sequence' => $finding->sequence,
            'finding_code' => $finding->finding_code,
            'description' => $finding->description,
            'section' => $finding->section_name,
            'penyulang_id' => $finding->penyulang_id,
            'keypoint_id' => $finding->keypoint_id,
            'asset_label' => $finding->asset_label,
            'latitude' => (float) $finding->latitude,
            'longitude' => (float) $finding->longitude,
            'gps_accuracy_m' => $finding->gps_accuracy_m === null ? null : (float) $finding->gps_accuracy_m,
            'occurred_at' => $finding->occurred_at->toIso8601String(),
            'received_at' => $finding->received_at->toIso8601String(),
            'status' => $finding->status,
            'photo_count' => $finding->media->count(),
            'personnel' => $finding->personnel->map(fn ($p) => ['id' => $p->field_personnel_id, 'name' => $p->personnel_name])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nilData(PasilaNilReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'status' => $report->status,
            'note' => $report->note,
            'latitude' => (float) $report->latitude,
            'longitude' => (float) $report->longitude,
            'occurred_at' => $report->occurred_at->toIso8601String(),
            'received_at' => $report->received_at->toIso8601String(),
            'superseded_by_finding_id' => $report->superseded_by_finding_id,
            'personnel' => $report->personnel->map(fn ($p) => ['id' => $p->field_personnel_id, 'name' => $p->personnel_name])->all(),
        ];
    }
}
