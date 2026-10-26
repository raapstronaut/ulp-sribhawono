<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Support\DataLoader;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Kerangka modul Inspeksi. Tabel kunjungan dan temuan inspeksi belum dibangun;
 * satu-satunya data asli adalah roster tim INSPEKSI pada master.
 */
class InspectionController extends Controller
{
    public function index(DataLoader $load): View
    {
        return view('admin.inspeksi.index', [
            'teams' => $load->attempt('teams', fn () => FieldTeam::query()
                ->where('role', 'INSPEKSI')
                ->withCount(['personnel' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('name')
                ->get(), collect()),
            'load' => $load,
        ]);
    }

    public function show(string $inspection): Response
    {
        // Domain inspeksi belum ada sehingga tidak ada kunjungan/temuan yang bisa ditemukan.
        return response()->view('admin.inspeksi.show', ['inspectionId' => $inspection], 404);
    }
}
