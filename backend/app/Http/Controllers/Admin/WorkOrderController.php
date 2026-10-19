<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Posko;
use App\Services\ShiftService;

class WorkOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ShiftService $shiftService)
    {
        // Work Order domain is not implemented yet.
        // We will pass empty states for the UI.

        $wo_shift_ini = 0;
        $wo_dikerjakan = 0;
        $wo_selesai = 0;
        $wo_carryover = 0;

        $poskos = Posko::where('is_active', true)->get();
        $target_total = $poskos->count() * 6; // 6 WO per posko

        $work_orders = []; // Empty collection

        $shift = $shiftService->current();
        $shift_name = $shift['name'];
        $shift_time = $shift['time'];
        $shift_index = $shift['index'];

        return view('admin.work-orders.index', compact(
            'wo_shift_ini',
            'wo_dikerjakan',
            'wo_selesai',
            'wo_carryover',
            'poskos',
            'target_total',
            'work_orders',
            'shift_name',
            'shift_time',
            'shift_index'
        ));
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        // Work Order domain is not implemented yet.
        // There are no actual Work Orders to show.
        abort(404);
    }
}
