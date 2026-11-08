<?php

namespace App\Services;

use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\PasilaSession;
use App\Models\WorkOrder;
use App\Models\WorkOrderReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan Dashboard lintas bidang; baca-saja dan tidak mengubah data operasional.
 * INSPEKSI memiliki kunjungan/temuan, bukan lifecycle Work Order.
 */
final class DashboardInsightsService
{
    public const PERIODS = [
        '7d' => '7 Hari',
        '30d' => '30 Hari',
        '90d' => '3 Bulan',
    ];

    private const DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

    private const FIELDS = [
        WorkOrder::TEAM_POSKO => 'POSKO',
        WorkOrder::TEAM_ROW => 'ROW',
        WorkOrder::TEAM_HARSUS => 'HARSUS',
        'INSPEKSI' => 'INSPEKSI',
    ];

    /** @return array<string, mixed> */
    public function get(string $requestedPeriod = '30d'): array
    {
        $period = array_key_exists($requestedPeriod, self::PERIODS) ? $requestedPeriod : '30d';
        $now = CarbonImmutable::now(ShiftService::TIMEZONE);
        $days = self::DAYS[$period];
        $from = $now->subDays($days - 1)->startOfDay();

        [$buckets, $dateToIndex] = $this->buckets($from, $days);
        $charts = [];
        foreach (self::FIELDS as $key => $label) {
            $charts[$key] = [
                'title' => $label,
                'first_label' => $key === 'INSPEKSI' ? 'Kunjungan' : 'WO dibuat',
                'second_label' => $key === 'INSPEKSI' ? 'Temuan dicatat' : 'WO selesai',
                'buckets' => array_map(static fn (array $bucket): array => $bucket + ['first' => 0, 'second' => 0], $buckets),
            ];
        }

        $fromUtc = $from->utc();
        $nowUtc = $now->utc();

        // Tanggal dikelompokkan berdasarkan WIB, bukan DATE(created_at) UTC dari SQL.
        foreach (WorkOrder::query()->whereBetween('created_at', [$fromUtc, $nowUtc])->get(['team_type', 'created_at']) as $wo) {
            $this->countInto($charts, $dateToIndex, $wo->team_type, 'first', $wo->created_at);
        }
        foreach (WorkOrder::query()->whereNotNull('completed_at')
            ->whereIn('status', ['SELESAI', 'SELESAI_DITERUSKAN'])
            ->whereBetween('completed_at', [$fromUtc, $nowUtc])
            ->get(['team_type', 'completed_at']) as $wo) {
            $this->countInto($charts, $dateToIndex, $wo->team_type, 'second', $wo->completed_at);
        }
        foreach (Inspection::query()->whereBetween('visited_at', [$fromUtc, $nowUtc])->get(['visited_at']) as $inspection) {
            $this->countInto($charts, $dateToIndex, 'INSPEKSI', 'first', $inspection->visited_at);
        }
        // Temuan dihitung ketika tercatat di aplikasi, bukan saat kunjungan dilakukan.
        foreach (InspectionFinding::query()->whereBetween('created_at', [$fromUtc, $nowUtc])->get(['created_at']) as $finding) {
            $this->countInto($charts, $dateToIndex, 'INSPEKSI', 'second', $finding->created_at);
        }

        foreach ($charts as &$chart) {
            $chart['max'] = max(1, ...array_map(
                static fn (array $bucket): int => max($bucket['first'], $bucket['second']),
                $chart['buckets'],
            ));
            $chart['total_first'] = array_sum(array_column($chart['buckets'], 'first'));
            $chart['total_second'] = array_sum(array_column($chart['buckets'], 'second'));
        }
        unset($chart);

        return [
            'period' => $period,
            'period_label' => self::PERIODS[$period],
            'start_date' => $from->toDateString(),
            'end_date' => $now->toDateString(),
            'charts' => $charts,
            'monitoring' => $this->monitoring(),
            'monitoring_items' => $this->monitoringItems(),
            'field_details' => $this->fieldDetails(),
        ];
    }

    /**
     * @return array{0: array<int, array{label: string, start: string, end: string}>, 1: array<string, int>}
     */
    private function buckets(CarbonImmutable $from, int $days): array
    {
        $buckets = [];
        $dateToIndex = [];
        for ($day = 0; $day < $days; $day++) {
            $date = $from->addDays($day);
            $index = $days === 7 ? $day : intdiv($day, 7);
            $dateToIndex[$date->toDateString()] = $index;
            if (! isset($buckets[$index])) {
                $buckets[$index] = [
                    'label' => $date->format('d/m'),
                    'start' => $date->format('d/m/Y'),
                    'end' => $date->format('d/m/Y'),
                ];
            }
            $buckets[$index]['end'] = $date->format('d/m/Y');
        }

        return [array_values($buckets), $dateToIndex];
    }

    /** @param array<string, mixed> $charts @param array<string, int> $dateToIndex */
    private function countInto(array &$charts, array $dateToIndex, string $team, string $metric, ?\DateTimeInterface $timestamp): void
    {
        if ($timestamp === null || ! isset($charts[$team])) {
            return;
        }

        $date = CarbonImmutable::instance($timestamp)->setTimezone(ShiftService::TIMEZONE)->toDateString();
        if (isset($dateToIndex[$date])) {
            $charts[$team]['buckets'][$dateToIndex[$date]][$metric]++;
        }
    }

    /**
     * Jumlah dari Work Order hanya untuk POSKO/ROW/HARSUS; INSPEKSI tidak
     * dipalsukan menjadi WO aktif atau WO terlambat.
     *
     * @return array<string, array<string, int|null>>
     */
    private function monitoring(): array
    {
        $woCounts = static fn ($query): array => $query
            ->select('team_type', DB::raw('count(*) AS total'))
            ->groupBy('team_type')
            ->pluck('total', 'team_type')
            ->all();

        $active = $woCounts(WorkOrder::query()->active());
        $overdue = $woCounts(WorkOrder::query()->overdue());
        $reports = WorkOrderReport::query()
            ->join('work_orders as wo', 'wo.id', '=', 'work_order_reports.work_order_id')
            ->where('work_order_reports.verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)
            ->select('wo.team_type', DB::raw('count(*) AS total'))
            ->groupBy('wo.team_type')
            ->pluck('total', 'wo.team_type')
            ->all();

        $result = [
            'active' => [],
            'overdue' => [],
            'verification' => [],
        ];
        foreach (self::FIELDS as $key => $_label) {
            $inspection = $key === 'INSPEKSI';
            $result['active'][$key] = $inspection ? null : (int) ($active[$key] ?? 0);
            $result['overdue'][$key] = $inspection ? null : (int) ($overdue[$key] ?? 0);
            $result['verification'][$key] = $inspection
                ? InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)->count()
                : (int) ($reports[$key] ?? 0);
        }

        return $result;
    }


    /**
     * Daftar pintas maksimum 60 aktivitas terkait per indikator.
     * Bukan pengganti daftar paginasi lengkap di setiap modul.
     *
     * @return array<string, array<int, array{team: string, title: string, status: string, url: string, at: string}>>
     */
    private function monitoringItems(): array
    {
        $formatWorkOrder = static fn (WorkOrder $wo): array => [
            'team' => $wo->team_type,
            'title' => $wo->code,
            'status' => $wo->status->label(),
            'url' => route('admin.work-orders.show', $wo),
            'at' => $wo->created_at?->toIso8601String() ?? '',
        ];

        $active = WorkOrder::query()->active()->orderByDesc('created_at')->limit(60)
            ->get(['id', 'code', 'team_type', 'status', 'created_at'])->map($formatWorkOrder)->all();
        $overdue = WorkOrder::query()->overdue()->orderBy('due_at')->limit(60)
            ->get(['id', 'code', 'team_type', 'status', 'created_at', 'due_at'])->map($formatWorkOrder)->all();

        $verification = [];
        foreach (WorkOrderReport::query()->with('workOrder:id,code,team_type')
            ->where('verification_status', WorkOrderReport::VERIFICATION_MENUNGGU)
            ->orderByDesc('created_at')->limit(60)->get() as $report) {
            if ($report->workOrder === null) {
                continue;
            }
            $verification[] = [
                'team' => $report->workOrder->team_type,
                'title' => $report->workOrder->code,
                'status' => 'Laporan menunggu verifikasi',
                'url' => route('admin.work-orders.show', $report->workOrder),
                'at' => $report->created_at?->toIso8601String() ?? '',
            ];
        }
        foreach (InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)
            ->orderByDesc('created_at')->limit(60)->get(['id', 'inspection_id', 'created_at']) as $finding) {
            $verification[] = [
                'team' => 'INSPEKSI',
                'title' => 'Temuan inspeksi',
                'status' => 'Menunggu validasi',
                'url' => route('admin.inspeksi.show', $finding->inspection_id),
                'at' => $finding->created_at?->toIso8601String() ?? '',
            ];
        }
        usort($verification, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return [
            'active' => $active,
            'overdue' => $overdue,
            'verification' => array_slice($verification, 0, 60),
        ];
    }

    /** @return array<string, array<int, array{label: string, value: int}>> */
    private function fieldDetails(): array
    {
        $activeByType = WorkOrder::query()->active()
            ->select('work_type', DB::raw('count(*) AS total'))
            ->groupBy('work_type')
            ->pluck('total', 'work_type')
            ->all();

        return [
            'POSKO' => [
                ['label' => 'WO APKUK aktif', 'value' => (int) ($activeByType[WorkOrder::TYPE_PEMASANGAN_APKUK] ?? 0)],
                ['label' => 'Sesi PASILA terbuka', 'value' => PasilaSession::query()->where('status', PasilaSession::STATUS_DIBUKA)->count()],
                ['label' => 'WO P0 aktif', 'value' => (int) ($activeByType[WorkOrder::TYPE_P0] ?? 0)],
            ],
            'ROW' => [
                ['label' => 'Rabas aktif', 'value' => (int) ($activeByType[WorkOrder::TYPE_RABAS] ?? 0)],
                ['label' => 'Pangkas aktif', 'value' => (int) ($activeByType[WorkOrder::TYPE_PANGKAS] ?? 0)],
                ['label' => 'Tebang aktif', 'value' => (int) ($activeByType[WorkOrder::TYPE_TEBANG] ?? 0)],
            ],
            'HARSUS' => [
                ['label' => 'WO aktif', 'value' => WorkOrder::query()->where('team_type', WorkOrder::TEAM_HARSUS)->active()->count()],
                ['label' => 'WO terlambat', 'value' => WorkOrder::query()->where('team_type', WorkOrder::TEAM_HARSUS)->overdue()->count()],
                ['label' => 'WO selesai 30 hari', 'value' => WorkOrder::query()
                    ->where('team_type', WorkOrder::TEAM_HARSUS)
                    ->whereIn('status', ['SELESAI', 'SELESAI_DITERUSKAN'])
                    ->where('completed_at', '>=', CarbonImmutable::now(ShiftService::TIMEZONE)->subDays(29)->startOfDay()->utc())
                    ->count()],
            ],
            'INSPEKSI' => [
                ['label' => 'Kunjungan hari ini', 'value' => Inspection::query()->where('visited_at', '>=', CarbonImmutable::now(ShiftService::TIMEZONE)->startOfDay()->utc())->count()],
                ['label' => 'Temuan hari ini', 'value' => InspectionFinding::query()->where('created_at', '>=', CarbonImmutable::now(ShiftService::TIMEZONE)->startOfDay()->utc())->count()],
                ['label' => 'Menunggu validasi', 'value' => InspectionFinding::query()->where('validation_status', InspectionFinding::VALIDATION_MENUNGGU)->count()],
            ],
        ];
    }
}