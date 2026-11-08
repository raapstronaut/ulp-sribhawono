<?php

namespace App\Services\Admin;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Services\ShiftService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Query baca-saja WO generik per tim (HARSUS, ROW) untuk halaman monitor Admin.
 */
class TeamWorkOrderService
{
    public const PER_PAGE = 20;

    public const STATUS_FILTER_OVERDUE = 'TERLAMBAT';

    public const SOURCES = [
        WorkOrder::SOURCE_ADMIN_MANUAL => 'Manual Admin',
        WorkOrder::SOURCE_SYSTEM => 'Otomatis sistem',
        WorkOrder::SOURCE_ESCALATION => 'Limpahan (eskalasi)',
    ];

    public function __construct(private readonly ShiftService $shiftService) {}

    /**
     * @return array{status: string, work_type: string, source: string, penyulang_id: string, work_date: string, q: string}
     */
    public function filters(Request $request): array
    {
        return [
            'status' => QueryInput::text($request, 'status', 32),
            'work_type' => QueryInput::text($request, 'work_type', 40),
            'source' => QueryInput::text($request, 'source', 24),
            'penyulang_id' => QueryInput::uuid($request, 'penyulang_id'),
            'work_date' => QueryInput::date($request, 'work_date'),
            'q' => QueryInput::text($request, 'q'),
        ];
    }

    /**
     * @param  array<int, string>  $workTypes  jenis yang sah untuk tim ini
     */
    public function paginate(string $teamType, array $workTypes, array $filters): LengthAwarePaginator
    {
        $query = WorkOrder::query()
            ->where('team_type', $teamType)
            ->with(['posko', 'penyulang', 'parent']);

        if ($filters['status'] === self::STATUS_FILTER_OVERDUE) {
            $query->overdue();
        } elseif ($status = WorkOrderStatus::tryFrom($filters['status'])) {
            $query->where('status', $status->value);
        }
        if (in_array($filters['work_type'], $workTypes, true)) {
            $query->where('work_type', $filters['work_type']);
        }
        if (array_key_exists($filters['source'], self::SOURCES)) {
            $query->where('source', $filters['source']);
        }
        if ($filters['penyulang_id'] !== '') {
            $query->where('penyulang_id', $filters['penyulang_id']);
        }
        if ($filters['work_date'] !== '') {
            $query->whereDate('work_date', $filters['work_date']);
        }
        if ($filters['q'] !== '') {
            $term = '%'.mb_strtoupper($filters['q']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where(DB::raw('upper(code)'), 'like', $term)
                    ->orWhere(DB::raw('upper(section_name)'), 'like', $term);
            });
        }

        return $query->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->tap(DataLoader::withinRange(...));
    }

    /**
     * @return array{active: int, overdue: int, completed_today: int, by_work_type: array<string, int>, by_source: array<string, int>}
     */
    public function summary(string $teamType): array
    {
        $base = fn () => WorkOrder::query()->where('team_type', $teamType);
        $dayStart = CarbonImmutable::parse($this->shiftService->today(), ShiftService::TIMEZONE)->startOfDay();

        return [
            'active' => $base()->active()->count(),
            'overdue' => $base()->overdue()->count(),
            'completed_today' => $base()
                ->where('status', WorkOrderStatus::Selesai->value)
                ->whereBetween('completed_at', [$dayStart->utc(), $dayStart->endOfDay()->utc()])
                ->count(),
            'by_work_type' => $base()->active()
                ->select('work_type', DB::raw('count(*) as total'))
                ->groupBy('work_type')
                ->pluck('total', 'work_type')
                ->map(fn ($total) => (int) $total)
                ->all(),
            'by_source' => $base()->active()
                ->select('source', DB::raw('count(*) as total'))
                ->groupBy('source')
                ->pluck('total', 'source')
                ->map(fn ($total) => (int) $total)
                ->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        $options = [];
        foreach (WorkOrderStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }
        $options[self::STATUS_FILTER_OVERDUE] = 'Terlambat';

        return $options;
    }
}
