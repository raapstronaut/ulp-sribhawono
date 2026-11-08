<?php

namespace App\Services\Pasila;

use App\Models\FieldTeam;
use App\Models\Posko;
use App\Services\PoskoCrewRotationService;
use Illuminate\Support\Collection;

/**
 * Pratinjau pasangan petugas POSKO untuk tanggal operasional + shift yang dipilih.
 * Hanya membaca roster, tidak mengubah sesi PASILA ataupun penugasan.
 */
final class PoskoCrewPreview
{
    public function __construct(private readonly PoskoCrewRotationService $rotations) {}

    /** @return array<string, mixed> */
    public function forPosko(Posko $posko, string $date, string $shiftCode): array
    {
        // Jangan kueri roster untuk tanggal sebelum anchor / parameter tidak valid.
        if ($this->rotations->forDate($date, $shiftCode) === null) {
            return $this->notReady('Belum berlaku atau tanggal/shift tidak valid.');
        }

        $teams = FieldTeam::query()
            ->where('posko_id', $posko->getKey())
            ->where('role', 'POSKO')
            ->where('is_active', true)
            ->with('personnel')
            ->get();

        return $this->fromTeams($teams, $date, $shiftCode);
    }

    /**
     * Dipisah agar pemilihan pasangan bisa diuji tanpa mengakses database.
     * @param Collection<int, FieldTeam> $teams
     * @return array<string, mixed>
     */
    public function fromTeams(Collection $teams, string $date, string $shiftCode): array
    {
        $rotation = $this->rotations->forDate($date, $shiftCode);
        if ($rotation === null) {
            return $this->notReady('Belum berlaku atau tanggal/shift tidak valid.');
        }

        if ($teams->count() !== 1) {
            return $this->notReady($teams->isEmpty()
                ? 'Tim induk POSKO aktif belum ditemukan.'
                : 'Tim induk POSKO aktif lebih dari satu. Periksa Master Data.');
        }

        $team = $teams->first();
        $roster = $this->rotations->roster($team);
        if (! $roster['roster_complete']) {
            return $this->notReady('Roster belum lengkap: wajib empat pasangan dengan dua petugas aktif per pasangan.');
        }

        $crew = collect($roster['crews'])->firstWhere('number', $rotation['crew_number']);
        if (! $crew || count($crew['members']) !== 2) {
            return $this->notReady('Pasangan untuk shift ini tidak lengkap.');
        }

        return [
            'status' => 'ready',
            'message' => 'Pratinjau berdasarkan roster; bukan bukti bahwa petugas telah menerima tugas.',
            'team_name' => $team->name,
            'cycle_day' => $rotation['cycle_day'],
            'crew_number' => $rotation['crew_number'],
            'off_crew_number' => $rotation['off_crew_number'],
            'members' => $crew['members'],
        ];
    }

    /** @return array<string, mixed> */
    private function notReady(string $message): array
    {
        return [
            'status' => 'unavailable',
            'message' => $message,
            'team_name' => null,
            'cycle_day' => null,
            'crew_number' => null,
            'off_crew_number' => null,
            'members' => [],
        ];
    }
}