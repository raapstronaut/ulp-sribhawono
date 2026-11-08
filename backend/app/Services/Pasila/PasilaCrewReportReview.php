<?php

namespace App\Services\Pasila;

use App\Models\FieldTeam;
use App\Models\PasilaSession;
use Illuminate\Support\Collection;

/**
 * Audit pelapor berbasis sesi PASILA. Tidak mengubah atau menolak laporan.
 * Jadwal dihitung dari tanggal/shift sesi, bukan received_at (offline-safe).
 * Menggunakan roster aktif SEKARANG; ini petunjuk audit, bukan bukti historis.
 */
final class PasilaCrewReportReview
{
    public function __construct(private readonly PoskoCrewPreview $previews) {}

    /** @return array<string, mixed> */
    public function forSession(PasilaSession $session): array
    {
        $session->loadMissing('shiftInstance');
        $shift = $session->shiftInstance;
        $teams = FieldTeam::query()
            ->where('posko_id', $session->posko_id)
            ->where('role', 'POSKO')
            ->where('is_active', true)
            ->with('personnel')
            ->get();

        $schedule = $shift
            ? $this->previews->fromTeams(
                $teams,
                $shift->operational_date->toDateString(),
                $shift->shift_code,
            )
            : ['status' => 'unavailable', 'message' => 'Shift sesi tidak tersedia.', 'members' => []];

        $entries = [];
        $findings = $session->findings()->with('personnel')->orderBy('sequence')->get();
        foreach ($findings as $finding) {
            $entries[] = [
                'label' => 'Temuan #'.$finding->sequence,
                'personnel_ids' => $finding->personnel->pluck('field_personnel_id')->all(),
                'personnel_names' => $finding->personnel->pluck('personnel_name')->all(),
            ];
        }

        $nil = $session->nilReport()->with('personnel')->first();
        if ($nil !== null) {
            $entries[] = [
                'label' => 'Laporan nihil',
                'personnel_ids' => $nil->personnel->pluck('field_personnel_id')->all(),
                'personnel_names' => $nil->personnel->pluck('personnel_name')->all(),
            ];
        }

        $closure = $session->closure()->first();
        if ($closure !== null) {
            $persons = collect(is_array($closure->personnel) ? $closure->personnel : []);
            $entries[] = [
                'label' => 'Penutupan sesi',
                'personnel_ids' => $persons->pluck('id')->all(),
                'personnel_names' => $persons->pluck('name')->all(),
            ];
        }

        return $this->reviewEntries($schedule, $entries);
    }

    /**
     * Murni perbandingan IDs, agar bisa diuji tanpa DB.
     * Sesuai hanya bila tepat kedua anggota pasangan terjadwal tercatat.
     * Satu anggota terjadwal = sebagian, bukan pelanggaran.
     * Anggota pasangan lain = perlu tinjauan, bukan penolakan laporan.
     *
     * @param array<string, mixed> $schedule
     * @param array<int, mixed> $reportedIds
     * @return array{status: string, label: string, note: string}
     */
    public function assess(array $schedule, array $reportedIds): array
    {
        $scheduled = collect($schedule['members'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->filter()->unique()->values()->all();
        if (($schedule['status'] ?? null) !== 'ready' || count($scheduled) !== 2) {
            return $this->result('unverifiable', 'Belum dapat diverifikasi', 'Tanggal, shift, atau roster aktif belum dapat dipastikan.');
        }

        $reported = collect($reportedIds)->map(fn ($id) => (string) $id)->filter()->unique()->values()->all();
        if ($reported === []) {
            return $this->result('unverifiable', 'Belum dapat diverifikasi', 'Identitas petugas pelapor tidak tercatat.');
        }
        if (array_diff($reported, $scheduled) !== []) {
            return $this->result('different', 'Perlu tinjauan', 'Ada pelapor di luar pasangan terjadwal. Bisa merupakan penggantian petugas; bukan bukti pelanggaran.');
        }
        if (count($reported) === 2) {
            return $this->result('match', 'Sesuai jadwal', 'Kedua anggota pasangan terjadwal tercatat sebagai pelapor.');
        }

        return $this->result('partial', 'Sebagian sesuai', 'Hanya satu anggota pasangan terjadwal yang tercatat; belum otomatis dianggap salah.');
    }

    /**
     * @param array<string, mixed> $schedule
     * @param array<int, array{label: string, personnel_ids: array<int, mixed>, personnel_names: array<int, mixed>}> $entries
     * @return array{schedule: array<string, mixed>, rows: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function reviewEntries(array $schedule, array $entries): array
    {
        $counts = ['match' => 0, 'partial' => 0, 'different' => 0, 'unverifiable' => 0];
        $rows = [];
        foreach ($entries as $entry) {
            $assessment = $this->assess($schedule, $entry['personnel_ids']);
            $counts[$assessment['status']]++;
            $rows[] = [
                'label' => $entry['label'],
                'personnel_names' => $entry['personnel_names'],
                'status' => $assessment['status'],
                'status_label' => $assessment['label'],
                'note' => $assessment['note'],
            ];
        }

        return ['schedule' => $schedule, 'rows' => $rows, 'counts' => $counts];
    }

    /** @return array{status: string, label: string, note: string} */
    private function result(string $status, string $label, string $note): array
    {
        return compact('status', 'label', 'note');
    }
}
