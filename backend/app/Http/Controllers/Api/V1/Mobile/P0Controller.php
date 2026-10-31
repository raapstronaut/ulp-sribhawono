<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\P0Item;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0 untuk akun tim POSKO (Batch B3.3): hanya P0 yang sudah menjadi pekerjaan (WO P0) milik Posko tim.
 * Usulan SYSTEM yang menunggu ACC atau ditolak tidak ditampilkan karena bukan pekerjaan. Pelaksanaan
 * memakai endpoint WO yang ada (/work-orders/{id}/start|reports|reschedules|issues).
 */
class P0Controller extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $team = $request->user()->fieldTeam;
        abort_unless($team !== null && $team->role === WorkOrder::TEAM_POSKO && $team->posko_id !== null, 403, 'Hanya akun tim POSKO yang dapat melihat P0.');

        $page = P0Item::query()
            ->where('posko_id', $team->posko_id)
            ->whereNotNull('work_order_id')
            ->whereHas('workOrder', fn ($q) => $q->whereNull('field_team_id')->orWhere('field_team_id', $team->getKey()))
            ->with(['session:id,code', 'workOrder', 'penyulang:id,kode_penyulang'])
            ->latest()
            ->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (P0Item $item) => [
                'id' => $item->getKey(),
                'code' => $item->code,
                'pasila_session' => ['id' => $item->pasila_session_id, 'code' => $item->session->code],
                'stage' => $item->stage(),
                'source_type' => $item->source_type,
                'section' => $item->section_name,
                'penyulang' => $item->penyulang->kode_penyulang,
                'description' => $item->description,
                'work_order' => [
                    'id' => $item->workOrder->getKey(),
                    'code' => $item->workOrder->code,
                    'status' => $item->workOrder->status->value,
                    'work_date' => $item->workOrder->work_date?->toDateString(),
                    'shift_code' => $item->workOrder->shift_code,
                ],
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'server_time' => now()->toIso8601String()],
        ]);
    }
}
