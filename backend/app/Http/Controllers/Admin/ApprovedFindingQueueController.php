<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use App\Services\Inspection\ApprovedInspectionFindingSource;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman tinjauan temuan ACC, bukan keputusan kelayakan atau generator WO.
 * Tidak ada operasi tulis. WO lama yang tidak tertaut masih harus diaudit.
 */
final class ApprovedFindingQueueController extends Controller
{
    public function index(Request $request, ApprovedInspectionFindingSource $source): View
    {
        $requestedTeam = $request->query('team');
        $team = is_string($requestedTeam) && in_array($requestedTeam, [
            WorkOrder::TEAM_POSKO, WorkOrder::TEAM_ROW, WorkOrder::TEAM_HARSUS,
        ], true) ? $requestedTeam : null;

        $findings = $source->query($team)->paginate(20)->withQueryString();

        return view('admin.inspeksi.queue', [
            'findings' => $findings,
            'team' => $team,
        ]);
    }

    /** Teks peninjauan, tidak pernah menyatakan kandidat aman untuk diterbitkan. */
    public static function reviewReason(InspectionFinding $finding): string
    {
        if (! $finding->suggested_team_type) {
            return 'Tim tujuan belum ditetapkan Admin.';
        }

        if ($finding->suggested_team_type === WorkOrder::TEAM_POSKO) {
            if (! $finding->isApprovedPoskoApkukTarget()) {
                return 'Identitas tiang atau kebutuhan APKUK belum lengkap.';
            }
            return 'Perlu pemeriksaan Section–Posko, kuota shift, jadwal dan duplikasi.';
        }

        if ($finding->suggested_team_type === WorkOrder::TEAM_ROW) {
            return 'Perlu pemeriksaan rencana ROW, jenis kerja, tim, kuota dan duplikasi.';
        }

        if ($finding->suggested_team_type === WorkOrder::TEAM_HARSUS) {
            return $finding->keypoint_id === null
                ? 'Aset jaringan belum dikaitkan dengan keypoint terverifikasi.'
                : 'Perlu penetapan HARSUS, jenis kerja, jadwal dan pemeriksaan duplikasi.';
        }

        return 'Saran tim tidak dikenal; tinjau secara manual.';
    }
}
