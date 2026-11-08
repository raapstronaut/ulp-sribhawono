<?php

namespace App\Services\Admin;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Services\ShiftService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Statistik WO untuk Analitik dan ekspor laporan. Semua angka dihitung dari baris
 * work_orders pada periode tanggal kerja (WIB) yang sama sehingga konsisten antar-tabel.
 *
 * Definisi:
 * - Selesai = SELESAI atau SELESAI_DITERUSKAN.
 * - Tepat waktu = selesai dengan completed_at <= due_at (WO tanpa due_at tidak dinilai).
 * - Terlambat aktif = WO aktif yang due_at-nya sudah lewat.
 * - Durasi = started_at (atau scheduled_start_at) sampai completed_at, dalam jam.
 */
class WorkOrderAnalyticsService
{
    /**
     * @param  array{from: string, to: string, team_type?: string, posko_id?: string, penyulang_id?: string}  $filters
     */
    public function query(array $filters): Builder
    {
        // whereDate: konsisten di PostgreSQL (date) dan SQLite (teks Y-m-d H:i:s).
        return WorkOrder::query()
            ->whereDate('work_date', '>=', $filters['from'])
            ->whereDate('work_date', '<=', $filters['to'])
            ->when(($filters['team_type'] ?? '') !== '', fn ($q) => $q->where('team_type', $filters['team_type']))
            ->when(($filters['posko_id'] ?? '') !== '', fn ($q) => $q->where('posko_id', $filters['posko_id']))
            ->when(($filters['penyulang_id'] ?? '') !== '', fn ($q) => $q->where('penyulang_id', $filters['penyulang_id']));
    }

    /**
     * @param  array{from: string, to: string, team_type?: string, posko_id?: string, penyulang_id?: string}  $filters
     * @return array<string, mixed>
     */
    public function summarize(array $filters): array
    {
        $rows = $this->query($filters)->with(['posko:id,nama_posko', 'penyulang:id,nama_penyulang'])
            ->get(['id', 'team_type', 'work_type', 'status', 'posko_id', 'penyulang_id', 'work_date', 'due_at', 'started_at', 'scheduled_start_at', 'completed_at']);
        $now = now();
        $days = (int) round((strtotime($filters['to']) - strtotime($filters['from'])) / 86400) + 1;
        $bucket = $days <= 31
            ? fn (WorkOrder $wo) => $wo->work_date->format('Y-m-d')
            : fn (WorkOrder $wo) => $wo->work_date->format('Y-m');

        return [
            'performance' => $this->metrics($rows, $now),
            'by_period' => $this->grouped($rows, $bucket, $now)->sortKeys()->all(),
            'period_granularity' => $days <= 31 ? 'harian' : 'bulanan',
            'by_work_type' => $this->grouped($rows, fn (WorkOrder $wo) => $wo->workTypeLabel(), $now)->sortKeys()->all(),
            'by_posko' => $this->grouped($rows, fn (WorkOrder $wo) => $wo->posko?->nama_posko ?? 'Tanpa Posko', $now)->sortKeys()->all(),
            'by_penyulang' => $this->grouped($rows, fn (WorkOrder $wo) => $wo->penyulang?->nama_penyulang ?? 'Tanpa penyulang', $now)->sortKeys()->all(),
            'by_status' => collect(WorkOrderStatus::cases())->mapWithKeys(fn (WorkOrderStatus $s) => [$s->label() => $rows->where('status', $s)->count()])->all(),
        ];
    }

    /**
     * @return Collection<string, array<string, int|float|null>>
     */
    private function grouped(Collection $rows, callable $key, $now): Collection
    {
        return $rows->groupBy($key)->map(fn (Collection $group) => $this->metrics($group, $now));
    }

    /**
     * @return array{total: int, active: int, completed: int, cancelled: int, on_time: int, late_completed: int, overdue_active: int, on_time_rate: ?float, avg_hours: ?float}
     */
    public function metrics(Collection $rows, $now): array
    {
        $completed = $rows->filter(fn (WorkOrder $wo) => in_array($wo->status, [WorkOrderStatus::Selesai, WorkOrderStatus::SelesaiDiteruskan], true));
        $judged = $completed->filter(fn (WorkOrder $wo) => $wo->due_at !== null && $wo->completed_at !== null);
        $onTime = $judged->filter(fn (WorkOrder $wo) => $wo->completed_at->lte($wo->due_at))->count();
        $durations = $completed->map(function (WorkOrder $wo) {
            $start = $wo->started_at ?? $wo->scheduled_start_at;

            return $start && $wo->completed_at && $wo->completed_at->gte($start) ? $start->diffInMinutes($wo->completed_at) / 60 : null;
        })->filter(fn ($v) => $v !== null);

        return [
            'total' => $rows->count(),
            'active' => $rows->filter(fn (WorkOrder $wo) => $wo->status->isActive())->count(),
            'completed' => $completed->count(),
            'cancelled' => $rows->where('status', WorkOrderStatus::Dibatalkan)->count(),
            'on_time' => $onTime,
            'late_completed' => $judged->count() - $onTime,
            'overdue_active' => $rows->filter(fn (WorkOrder $wo) => $wo->isOverdue($now))->count(),
            'on_time_rate' => $judged->count() > 0 ? round($onTime / $judged->count() * 100, 1) : null,
            'avg_hours' => $durations->isNotEmpty() ? round($durations->avg(), 1) : null,
        ];
    }

    /**
     * Baris ekspor CSV (satu baris per WO).
     *
     * @param  array{from: string, to: string, team_type?: string, posko_id?: string, penyulang_id?: string}  $filters
     * @return \Generator<int, array<int, string|int|null>>
     */
    public function exportRows(array $filters): \Generator
    {
        $tz = ShiftService::TIMEZONE;
        $fmt = fn ($dt) => $dt ? $dt->copy()->timezone($tz)->format('Y-m-d H:i') : null;
        yield ['kode', 'tim', 'jenis_pekerjaan', 'sumber', 'status', 'posko', 'penyulang', 'section', 'target', 'tanggal_kerja', 'shift', 'batas_waktu_wib', 'mulai_wib', 'selesai_wib', 'tepat_waktu', 'terlambat_aktif', 'apkuk_target_bh', 'apkuk_terpasang_bh', 'dibuat_oleh', 'dibuat_wib'];

        foreach ($this->query($filters)->with(['posko', 'penyulang', 'apkukTarget', 'locationTarget', 'createdBy'])->orderBy('work_date')->orderBy('code')->lazy(500) as $wo) {
            $judged = $wo->completed_at && $wo->due_at;
            yield [
                $wo->code,
                $wo->team_type,
                $wo->work_type,
                $wo->source,
                $wo->status->value,
                $wo->posko?->nama_posko,
                $wo->penyulang?->nama_penyulang,
                $wo->section_name,
                $wo->targetLabel(),
                $wo->work_date?->toDateString(),
                $wo->shift_code,
                $fmt($wo->due_at),
                $fmt($wo->started_at),
                $fmt($wo->completed_at),
                $judged ? ($wo->completed_at->lte($wo->due_at) ? 'YA' : 'TIDAK') : null,
                $wo->isOverdue() ? 'YA' : 'TIDAK',
                $wo->apkukTarget?->target_quantity,
                $wo->apkukTarget?->installed_quantity,
                $wo->createdBy?->displayName(),
                $fmt($wo->created_at),
            ];
        }
    }
}
