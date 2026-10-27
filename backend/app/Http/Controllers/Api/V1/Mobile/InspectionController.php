<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Services\Inspection\InspectionService;
use App\Services\Work\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kunjungan inspeksi dari akun tim INSPEKSI: dengan temuan atau "Tidak Ada Temuan".
 */
class InspectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureInspector($request);
        $page = Inspection::query()->where('field_team_id', $request->user()->field_team_id)
            ->withCount('findings')
            ->orderByDesc('visited_at')
            ->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Inspection $i) => [
                'id' => $i->getKey(),
                'code' => $i->code,
                'visited_at' => $i->visited_at?->toIso8601String(),
                'has_finding' => $i->has_finding,
                'finding_count' => $i->findings_count,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'server_time' => now()->toIso8601String()],
        ]);
    }

    public function store(Request $request, InspectionService $service): JsonResponse
    {
        $this->ensureInspector($request);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:64'],
            'penyulang_id' => ['required', 'uuid'],
            'section_name' => ['nullable', 'string', 'max:255'],
            'visited_at' => ['required', 'date'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'personnel_ids' => ['nullable', 'array', 'max:20'],
            'personnel_ids.*' => ['uuid'],
            'personnel_names' => ['nullable', 'array', 'max:20'],
            'personnel_names.*' => ['string', 'max:255'],
            'findings' => ['nullable', 'array', 'max:30'],
            'findings.*.finding_code' => ['required', 'string', 'max:80'],
            'findings.*.description' => ['required', 'string', 'max:2000'],
            'findings.*.keypoint_id' => ['nullable', 'integer'],
            'findings.*.asset_label' => ['nullable', 'string', 'max:255'],
            'findings.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'findings.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'findings.*.suggested_team_type' => ['nullable', 'string', 'max:24'],
            'findings.*.photos' => ['nullable', 'array', 'max:10'],
            'findings.*.photos.*' => ['file', 'mimes:'.implode(',', MediaStore::MIMES), 'max:'.MediaStore::MAX_KILOBYTES],
        ]);

        $findings = [];
        foreach ($data['findings'] ?? [] as $index => $finding) {
            $finding['photos'] = $request->file("findings.{$index}.photos", []);
            $findings[] = $finding;
        }

        $user = $request->user();
        $inspection = $service->record(
            ['field_team_id' => $user->field_team_id, 'findings' => $findings] + $data,
            $user,
            $user->fieldTeam,
            Inspection::SOURCE_MOBILE,
            requirePhotos: true,
        );

        return response()->json([
            'data' => [
                'id' => $inspection->getKey(),
                'code' => $inspection->code,
                'has_finding' => $inspection->has_finding,
                'findings' => $inspection->findings()->get()->map(fn (InspectionFinding $f) => [
                    'id' => $f->getKey(),
                    'finding_code' => $f->finding_code,
                    'validation_status' => $f->validation_status,
                ])->all(),
            ],
            'meta' => ['server_time' => now()->toIso8601String()],
        ], 201);
    }

    private function ensureInspector(Request $request): void
    {
        abort_unless($request->user()->role === InspectionService::TEAM_ROLE, 403, 'Hanya akun tim INSPEKSI yang dapat mencatat inspeksi.');
    }
}
