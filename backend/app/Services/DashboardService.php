<?php

namespace App\Services;

use App\Models\Penyulang;
use App\Models\Posko;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    private const APKUK_TARGET_PER_POSKO_SHIFT = 6;

    public function __construct(private readonly ShiftService $shiftService) {}

    /**
     * Build the Admin Dashboard contract from available master data.
     * Transaction values stay null until their canonical domains exist.
     */
    public function getDashboardData(): array
    {
        $now = Carbon::now(ShiftService::TIMEZONE);
        $shift = $this->shiftService->current($now);
        $poskos = Posko::query()
            ->where('is_active', true)
            ->orderBy('nama_posko')
            ->get();
        $activePenyulangCount = Penyulang::query()
            ->where('is_active', true)
            ->count();

        return [
            'context' => $this->getContext($now, $shift, $poskos),
            'shiftData' => $shift,
            'kpis' => $this->getKpis(),
            'teamSummaries' => $this->getTeamSummaries($poskos->count()),
            'poskoStatuses' => $this->getPoskoStatuses($poskos, $shift),
            'adminActionSummaries' => $this->getAdminActionSummaries(),
            'recentActivities' => $this->getRecentActivities(),
            'dataReadiness' => $this->getDataReadiness($poskos->count(), $activePenyulangCount),
        ];
    }

    private function getContext(Carbon $now, array $shift, Collection $poskos): array
    {
        return [
            'updated_at' => $now->toIso8601String(),
            'updated_at_label' => $now->format('d-m-Y H:i').' WIB',
            'period_label' => 'Shift berjalan',
            'filters' => [
                'available' => false,
                'reason' => 'Filter periode, shift, dan Posko akan diaktifkan setelah transaksi operasional tersedia.',
                'period' => 'Shift berjalan',
                'shift' => $shift['code'],
                'poskos' => $poskos->map(fn (Posko $posko): array => [
                    'id' => $posko->id,
                    'name' => $posko->nama_posko,
                ])->values()->all(),
            ],
        ];
    }

    private function getKpis(): array
    {
        return [
            'wo_active' => $this->unavailableMetric(
                'WO Aktif',
                'Pekerjaan yang sedang ditugaskan atau dikerjakan.',
                'admin.work-orders.index',
                'Buka halaman Work Order',
            ),
            'wo_completed' => $this->unavailableMetric(
                'WO Selesai',
                'Pekerjaan selesai pada periode yang dipilih.',
                'admin.work-orders.index',
                'Buka halaman Work Order',
            ),
            'wo_overdue' => $this->unavailableMetric(
                'WO Terlambat',
                'Pekerjaan melewati jadwal dan perlu perhatian.',
                'admin.work-orders.index',
                'Buka halaman Work Order',
            ),
            'p0_active' => $this->unavailableMetric(
                'P0 Aktif',
                'P0 POSKO yang terkait dengan PASILA.',
                'admin.p0.index',
                'Buka halaman P0',
            ),
            'pasila' => $this->unavailableMetric(
                'PASILA',
                'Sesi PASILA pada shift dan Posko terkait.',
                'admin.pasila.index',
                'Buka halaman PASILA',
            ),
            'approvals' => $this->unavailableMetric(
                'Menunggu Approval',
                'P0 SYSTEM, temuan inspeksi, dan eskalasi yang perlu keputusan Admin.',
                'admin.reviews.index',
                'Buka Review & Validasi',
            ),
        ];
    }

    private function unavailableMetric(
        string $title,
        string $description,
        ?string $routeName = null,
        ?string $routeLabel = null,
    ): array {
        return [
            'title' => $title,
            'value' => null,
            'available' => false,
            'status' => 'Data belum tersedia',
            'description' => $description,
            'navigation' => [
                'available' => $routeName !== null,
                'route_name' => $routeName,
                'label' => $routeLabel,
                'filter_applied' => false,
            ],
        ];
    }

    private function getTeamSummaries(int $activePoskoCount): array
    {
        return [
            'posko' => [
                'name' => 'POSKO',
                'description' => 'WO APKUK, PASILA, P0, dan reschedule per shift.',
                'master_context' => "{$activePoskoCount} Posko aktif pada master",
                'route_name' => 'admin.work-orders.index',
                'route_label' => 'Buka Work Order',
                'metrics' => [
                    $this->teamMetric('APKUK', 'WO shift'),
                    $this->teamMetric('PASILA', 'Sesi shift'),
                    $this->teamMetric('P0', 'Pekerjaan aktif'),
                    $this->teamMetric('Reschedule', 'Perlu tindak lanjut'),
                ],
            ],
            'harsus' => [
                'name' => 'HARSUS',
                'description' => 'WO pekerjaan khusus dari Admin atau hasil eskalasi.',
                'master_context' => 'Cakupan operasional ULP',
                'route_name' => 'admin.harsus.index',
                'route_label' => 'Buka HARSUS',
                'metrics' => [
                    $this->teamMetric('Aktif', 'WO khusus'),
                    $this->teamMetric('Selesai', 'Hari ini'),
                ],
            ],
            'inspeksi' => [
                'name' => 'INSPEKSI',
                'description' => 'Kegiatan inspeksi, hasil kunjungan, dan temuan.',
                'master_context' => 'Cakupan operasional ULP',
                'route_name' => 'admin.inspeksi.index',
                'route_label' => 'Buka Inspeksi',
                'metrics' => [
                    $this->teamMetric('Kegiatan', 'Hari ini'),
                    $this->teamMetric('Temuan', 'Dikirim'),
                ],
            ],
            'row' => [
                'name' => 'ROW',
                'description' => 'WO vegetasi RABAS, PANGKAS, dan TEBANG.',
                'master_context' => 'Cakupan operasional ULP',
                'route_name' => 'admin.row.index',
                'route_label' => 'Buka ROW',
                'metrics' => [
                    $this->teamMetric('RABAS', 'WO aktif'),
                    $this->teamMetric('PANGKAS', 'WO aktif'),
                    $this->teamMetric('TEBANG', 'WO aktif'),
                ],
            ],
        ];
    }

    private function teamMetric(string $label, string $detail): array
    {
        return [
            'label' => $label,
            'detail' => $detail,
            'value' => null,
            'available' => false,
        ];
    }

    private function getPoskoStatuses(Collection $poskos, array $shift): array
    {
        return $poskos->map(fn (Posko $posko): array => [
            'id' => $posko->id,
            'code' => $posko->kode_posko,
            'name' => $posko->nama_posko,
            'shift_code' => $shift['code'],
            'shift_name' => $shift['name'],
            'apkuk_target' => self::APKUK_TARGET_PER_POSKO_SHIFT,
            'apkuk_completed' => null,
            'pasila_status' => null,
            'p0_status' => null,
            'attention' => null,
            'transactions_available' => false,
        ])->values()->all();
    }

    private function getAdminActionSummaries(): array
    {
        return [
            $this->unavailableAdminAction('P0 SYSTEM menunggu ACC', 'Usulan P0 otomatis yang perlu keputusan Admin.'),
            $this->unavailableAdminAction('Temuan INSPEKSI', 'Temuan yang menunggu validasi dan penentuan tindak lanjut.'),
            $this->unavailableAdminAction('Eskalasi lintas tim', 'Permintaan eskalasi yang menunggu keputusan Admin.'),
            $this->unavailableAdminAction('Kebutuhan pengganti P0', 'Pengganti manual setelah usulan P0 SYSTEM ditolak.'),
        ];
    }

    private function unavailableAdminAction(string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'count' => null,
            'available' => false,
            'route_name' => null,
        ];
    }

    private function getRecentActivities(): array
    {
        return [
            'available' => false,
            'reason' => 'Aktivitas akan ditampilkan setelah event pekerjaan dan audit operasional tersedia.',
            'items' => [],
        ];
    }

    private function getDataReadiness(int $activePoskoCount, int $activePenyulangCount): array
    {
        return [
            'masters' => [
                'available' => true,
                'active_poskos' => $activePoskoCount,
                'active_penyulangs' => $activePenyulangCount,
            ],
            'transactions' => [
                'available' => false,
                'label' => 'Domain transaksi belum tersedia',
                'description' => 'WO, PASILA, P0, approval, inspeksi, dan ROW belum dapat dihitung.',
                'route_name' => null,
                'route_label' => null,
            ],
            'network' => [
                'available' => false,
                'label' => 'Data jaringan canonical belum siap',
                'description' => 'Section, aset, koordinat, histori, dan foto masih menunggu validasi.',
                'route_name' => null,
                'route_label' => null,
            ],
            // Peta berjalan dari data sinkron (keypoints, apkuks, gangguans), bukan aset canonical.
            'map' => [
                'available' => true,
                'label' => 'Peta Jaringan tersedia',
                'description' => 'Marker Keypoint dan layer APKUK berkoordinat dari data sinkron, dengan pencarian dan filter Penyulang. Gangguan tampil sebagai daftar karena belum memiliki koordinat kejadian.',
                'route_name' => 'admin.maps.index',
                'route_label' => 'Buka Peta Jaringan',
            ],
        ];
    }
}
