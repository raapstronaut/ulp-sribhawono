<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Posko;

class WorkOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
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

        $now = now()->timezone('Asia/Jakarta');
        $hour = $now->hour;
        
        if ($hour >= 8 && $hour < 16) {
            $shift_name = 'Pagi';
            $shift_time = '08:00 - 16:00 WIB';
            $shift_index = '1/3';
        } elseif ($hour >= 16) {
            $shift_name = 'Sore';
            $shift_time = '16:00 - 00:00 WIB';
            $shift_index = '2/3';
        } else {
            $shift_name = 'Malam';
            $shift_time = '00:00 - 08:00 WIB';
            $shift_index = '3/3';
        }

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
