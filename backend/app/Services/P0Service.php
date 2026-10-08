<?php

namespace App\Services;

class P0Service
{
    /**
     * Get summary data for P0 Monitor Dashboard
     */
    public function getDashboardData(): array
    {
        // Currently the P0 domain doesn't exist, returning empty state gracefully
        return [
            'p0_aktif' => 0,
            'p0_baru' => 0,
            'p0_dikerjakan' => 0,
            'p0_selesai' => 0,
            'p0_list' => [], // no fake records
        ];
    }

    /**
     * Get detail data for a specific P0
     */
    public function getDetailData($p0_id): array
    {
        // Without DB schema, we just return empty scaffold so the UI doesn't crash,
        // while also NOT displaying any fake operational data.
        return [
            'p0_id' => $p0_id,
            'status' => 'Tidak Ditemukan',
            'pelaksana' => 'POSKO',
            'waktu_dibuat' => '-',
            'waktu_diterima' => '-',
            'waktu_dikerjakan' => '-',
            'waktu_selesai' => '-',
            'sumber' => '-',
            'target_date' => '-',
            'deskripsi' => '-',
            'aset' => '-',
            'penyulang' => '-',
            'posko' => '-',
            'koordinat' => null, // force empty state map
            'pemadaman' => '-',
            'material' => '-',
            'k3' => '-',
            'photos' => [], // no fake photos
            'history' => [],
            'has_reschedule_request' => false,
            'reschedule_target' => '-',
            'reschedule_reason' => '-',
            'petugas' => 'Petugas akan dicatat saat penyelesaian pekerjaan.',
        ];
    }
}
