<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Penyulang;
use App\Models\ReferenceCode;
use App\Models\WorkOrderIssue;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data awal aplikasi lapangan: tim, petugas aktif, kode dropdown sesuai role, shift, penyulang.
 */
class BootstrapController extends Controller
{
    public function show(Request $request, ShiftService $shifts): JsonResponse
    {
        $user = $request->user();
        $team = $user->fieldTeam()->with(['personnel' => fn ($q) => $q->where('is_active', true)->orderBy('name'), 'posko'])->first();
        $role = $user->role;

        $codes = [];
        foreach (array_keys(ReferenceCode::CATEGORIES) as $category) {
            $codes[$category] = ReferenceCode::query()->activeFor($category, $role)
                ->get(['code', 'label', 'sort_order'])
                ->map(fn (ReferenceCode $code) => ['code' => $code->code, 'label' => $code->label])
                ->all();
        }

        return response()->json([
            'data' => [
                'team' => [
                    'id' => $team->getKey(),
                    'code' => $team->code,
                    'name' => $team->name,
                    'role' => $team->role,
                    'posko' => $team->posko ? ['id' => $team->posko->getKey(), 'name' => $team->posko->nama_posko] : null,
                ],
                'personnel' => $team->personnel->map(fn ($p) => ['id' => $p->getKey(), 'code' => $p->personnel_code, 'name' => $p->name])->all(),
                'reference_codes' => $codes,
                'issue_types' => collect(WorkOrderIssue::TYPE_LABELS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all(),
                'shifts' => array_map(fn (string $code) => $shifts->describe($code), $shifts->codes()),
                'current_shift' => $shifts->current(),
                'penyulangs' => Penyulang::query()->where('is_active', true)->orderBy('kode_penyulang')
                    ->get(['id', 'kode_penyulang', 'nama_penyulang'])
                    ->map(fn ($p) => ['id' => $p->getKey(), 'code' => $p->kode_penyulang, 'name' => $p->nama_penyulang])->all(),
            ],
            'meta' => ['server_time' => now()->toIso8601String()],
        ]);
    }
}
