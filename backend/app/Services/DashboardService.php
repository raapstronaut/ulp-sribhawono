<?php

namespace App\Services;

use App\Models\Posko;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * Get all dashboard data.
     */
    public function getDashboardData(): array
    {
        $now = Carbon::now('Asia/Jakarta');
        $shiftData = $this->getCurrentShift($now);

        return [
            'currentTime' => $now,
            'shiftData' => $shiftData,
            'kpi' => $this->getKpiData($shiftData),
            'adminActions' => $this->getAdminActions(),
            'fieldActivities' => $this->getFieldActivities(),
            'poskoStatuses' => $this->getPoskoStatuses(),
            'mapMarkers' => $this->getMapMarkers(),
            'recentActivities' => $this->getRecentActivities(),
        ];
    }

    /**
     * Determine current shift based on time.
     */
    private function getCurrentShift(Carbon $now): array
    {
        $hour = $now->hour;
        
        if ($hour >= 8 && $hour < 16) {
            return ['name' => 'Pagi', 'time' => '08:00 - 16:00 WIB', 'code' => 'A'];
        } elseif ($hour >= 16 && $hour < 24) {
            return ['name' => 'Sore', 'time' => '16:00 - 00:00 WIB', 'code' => 'B'];
        } else {
            return ['name' => 'Malam', 'time' => '00:00 - 08:00 WIB', 'code' => 'C'];
        }
    }

    /**
     * Get KPI data.
     */
    private function getKpiData(array $shiftData): array
    {
        // 1. P0 Aktif - no table yet
        $p0Aktif = 0;

        // 2. Work Order Shift Ini - no table yet
        // Target is 6 WO per active Posko
        $activePoskosCount = Posko::where('is_active', true)->count();
        $targetWo = $activePoskosCount * 6;
        $woSelesai = 0;
        $woProses = 0;

        // 3. PASILA Shift Ini - no table yet
        $pasilaAktif = 0;
        $pasilaSelesai = 0;
        $pasilaEskalasi = 0;

        // 4. Menunggu Validasi - no table yet
        $menungguValidasi = 0;

        return [
            'p0' => [
                'aktif' => $p0Aktif,
            ],
            'wo' => [
                'selesai' => $woSelesai,
                'proses' => $woProses,
                'target' => $targetWo,
                'total' => $woSelesai + $woProses,
            ],
            'pasila' => [
                'aktif' => $pasilaAktif,
                'selesai' => $pasilaSelesai,
                'eskalasi' => $pasilaEskalasi,
                'total' => $pasilaAktif + $pasilaSelesai,
            ],
            'validasi' => [
                'menunggu' => $menungguValidasi,
            ]
        ];
    }

    /**
     * Get pending admin actions.
     */
    private function getAdminActions(): array
    {
        // No transaction tables yet
        return [];
    }

    /**
     * Get field activities summary.
     */
    private function getFieldActivities(): array
    {
        // No transaction tables yet
        $poskoCount = Posko::where('is_active', true)->count();

        return [
            'harsus' => [
                'wo_khusus_aktif' => 0,
                'selesai_hari_ini' => 0,
            ],
            'posko' => [
                'count' => $poskoCount,
                'wo_berjalan' => 0,
                'pasila_aktif' => 0,
            ],
            'inspeksi' => [
                'berjalan' => 0,
                'temuan_dikirim' => 0,
            ],
            'row' => [
                'pekerjaan_aktif' => 0,
                'selesai_hari_ini' => 0,
            ]
        ];
    }

    /**
     * Get status for each posko.
     */
    private function getPoskoStatuses(): array
    {
        $poskos = Posko::where('is_active', true)->get();
        $statuses = [];

        // Define exact order
        $order = [
            'Sribhawono' => 1,
            'Pugung' => 2,
            'Jabung' => 3,
            'Pasir Sakti' => 4,
            'Way Jepara' => 5,
            'Tridatu' => 6,
        ];

        // Sort collection based on predefined order
        $poskos = $poskos->sortBy(function($posko) use ($order) {
            return $order[$posko->nama_posko] ?? 99;
        });

        foreach ($poskos as $posko) {
            $statuses[] = [
                'id' => $posko->id,
                'kode' => $posko->kode_posko ?? substr($posko->nama_posko, 0, 2),
                'nama' => $posko->nama_posko,
                'wo_selesai' => 0,
                'wo_berjalan' => 0,
                'wo_target' => 6,
                'pasila_aktif' => 0,
                'pasila_selesai' => 0,
                'eskalasi' => 0,
                'perlu_perhatian' => false,
            ];
        }

        return $statuses;
    }

    /**
     * Get map markers from real data.
     */
    private function getMapMarkers(): array
    {
        $markers = [];

        // Currently no operational transaction tables exist with coordinates
        // Do not use staging tables like VegetasiSourceRow for operational dashboard

        return $markers;
    }

    /**
     * Get recent activities timeline.
     */
    private function getRecentActivities(): array
    {
        // No transaction/audit tables yet
        return [];
    }
}
