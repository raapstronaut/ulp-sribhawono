<?php

namespace App\Services\Work;

use App\Models\FieldTeam;
use App\Models\WorkOrder;
use App\Services\Pasila\PasilaCrewReportReview;
use App\Services\Pasila\PoskoCrewPreview;

/**
 * Petunjuk audit READ ONLY untuk WO POSKO (APKUK / P0).
 * Tidak membatasi pengiriman laporan, tidak mengganti penugasan.
 * Menggunakan jadwal pada work_date + shift_code WO, BUKAN received_at laporan.
 * Perbandingan memakai roster aktif saat diperiksa, bukan snapshot historis.
 * Jika WO pernah di-reschedule, jadwal pada WO sekarang bisa berbeda dari
 * jadwal yang berlaku saat laporan lama terjadi: tinjau manual.
 */
final class PoskoWorkOrderCrewReview
{
    public function __construct(
        private readonly PoskoCrewPreview $preview,
        private readonly PasilaCrewReportReview $assessor,
    ) {}

    /** @return array<string, mixed> */
    public function forWorkOrder(WorkOrder $workOrder): array
    {
        $isPosko = $workOrder->team_type === WorkOrder::TEAM_POSKO
            && in_array($workOrder->work_type, [WorkOrder::TYPE_P0, WorkOrder::TYPE_PEMASANGAN_APKUK], true);
        $workDate = $workOrder->work_date?->toDateString();
        $shiftCode = $workOrder->shift_code;

        if (! $isPosko) {
            $schedule = $this->unavailable('Audit pasangan hanya untuk WO POSKO jenis APKUK atau P0.');
        } elseif (! $workDate || ! $shiftCode || ! $workOrder->posko_id) {
            $schedule = $this->unavailable('Tanggal, shift, atau POSKO pada WO belum lengkap.');
        } else {
            $teams = FieldTeam::query()
                ->where('posko_id', $workOrder->posko_id)
                ->where('role', WorkOrder::TEAM_POSKO)
                ->where('is_active', true)
                ->with('personnel')
                ->get();
            $schedule = $this->preview->fromTeams($teams, $workDate, $shiftCode);
        }

        $entries = $workOrder->reports()->with('personnel')->orderBy('created_at')->get()
            ->map(function ($report): array {
                $persons = $report->personnel;

                return [
                    'id' => (string) $report->getKey(),
                    'label' => (string) $report->report_type,
                    'personnel_ids' => $persons->pluck('field_personnel_id')->all(),
                    'personnel_names' => $persons->pluck('personnel_name')->all(),
                    'has_unlinked_personnel' => $persons->contains(fn ($person) => $person->field_personnel_id === null),
                    'submitted_by_field_team_id' => $report->submitted_by_field_team_id,
                    'occurred_at' => $report->occurred_at?->toIso8601String(),
                    'received_at' => $report->received_at?->toIso8601String(),
                ];
            })->all();

        // Sumber temuan P0 bukan otomatis pelaksana WO P0.
        $p0 = $workOrder->work_type === WorkOrder::TYPE_P0 ? $workOrder->p0Item()->first() : null;
        $source = $p0 === null ? null : [
            'p0_id' => (string) $p0->getKey(),
            'p0_code' => $p0->code,
            'source_type' => $p0->source_type,
            'source_finding_id' => $p0->pasila_finding_id ?? $p0->inspection_finding_id,
        ];

        return [
            'work_order_id' => (string) $workOrder->getKey(),
            'work_type' => $workOrder->work_type,
            'work_date' => $workDate,
            'shift_code' => $shiftCode,
            'p0_source' => $source,
            ...$this->reviewEntries($schedule, $entries),
            'disclaimer' => 'Perbandingan informatif dengan roster aktif dan jadwal WO saat ini; bukan bukti pelanggaran atau snapshot historis.',
        ];
    }

    /**
     * Dipisah agar dapat dites tanpa database.
     * Status memakai aturan PASILA yang sudah teruji, bukan membuat aturan kedua.
     *
     * @param array<string, mixed> $schedule
     * @param array<int, array<string, mixed>> $entries
     * @return array{schedule: array<string,mixed>, rows: array<int,array<string,mixed>>, counts: array<string,int>}
     */
    public function reviewEntries(array $schedule, array $entries): array
    {
        $counts = ['match' => 0, 'partial' => 0, 'different' => 0, 'unverifiable' => 0];
        $rows = [];

        foreach ($entries as $entry) {
            $ids = $entry['personnel_ids'] ?? [];
            $assessment = $this->assessor->assess($schedule, $ids);

            // WO lama boleh menyimpan nama bebas tanpa ID roster. Jangan tampilkan
            // 'sesuai' atau 'sebagian sesuai' jika identitas belum bisa dipastikan.
            if (($entry['has_unlinked_personnel'] ?? false) && $assessment['status'] !== 'different') {
                $assessment = [
                    'status' => 'unverifiable',
                    'label' => 'Belum dapat diverifikasi',
                    'note' => 'Ada nama petugas yang belum terhubung ke ID roster. Periksa manual.',
                ];
            }

            $counts[$assessment['status']]++;
            $rows[] = [
                'id' => $entry['id'] ?? null,
                'label' => $entry['label'] ?? 'Laporan WO',
                'personnel_names' => $entry['personnel_names'] ?? [],
                'status' => $assessment['status'],
                'status_label' => $assessment['label'],
                'note' => $assessment['note'],
                'occurred_at' => $entry['occurred_at'] ?? null,
                'received_at' => $entry['received_at'] ?? null,
                'submitted_by_field_team_id' => $entry['submitted_by_field_team_id'] ?? null,
            ];
        }

        return ['schedule' => $schedule, 'rows' => $rows, 'counts' => $counts];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'message' => $reason, 'members' => [], 'crew_number' => null];
    }
}
