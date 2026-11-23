<?php

namespace App\Services\Inspection;

use App\Models\InspectionFinding;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Antrean tinjauan universal dari temuan INSPEKSI yang sudah disetujui Admin.
 *
 * Semua jenis temuan (POSKO/ROW/HARSUS, maupun tanpa saran tim) boleh tampil.
 * Ini BUKAN antrean siap-generate: saran tim belum tentu penetapan final Admin,
 * relasi WO lama tidak selalu tercatat pada temuan, dan target/duplikasi/penjadwalan
 * belum diverifikasi di sini. Jangan gunakan hasilnya langsung untuk create WO.
 *
 * Read-only. Tidak mengubah perilaku generator APKUK legacy.
 */
final class ApprovedInspectionFindingSource
{
    private const SUGGESTED_TEAMS = [
        WorkOrder::TEAM_POSKO,
        WorkOrder::TEAM_ROW,
        WorkOrder::TEAM_HARSUS,
    ];

    /**
     * Mengambil temuan ACC Admin yang belum memiliki tautan follow-up WO.
     * Temuan tanpa saran tim tetap ikut saat filter tim tidak diberikan,
     * agar tidak tersembunyi dari antrean peninjauan Admin.
     *
     * @return Collection<int, InspectionFinding>
     */
    public function unlinked(?string $suggestedTeamType = null, bool $fullOnly = false): Collection
    {
        // $fullOnly is deprecated: one form, all approved inspections are eligible for review.
        return $this->query($suggestedTeamType, $fullOnly)
            ->get()
            ->filter(fn (InspectionFinding $finding): bool => self::matches($finding, $suggestedTeamType, $fullOnly))
            ->values();
    }

    /** @return Builder<InspectionFinding> */
    public function query(?string $suggestedTeamType = null, bool $fullOnly = false): Builder
    {
        self::validateTeam($suggestedTeamType);

        return InspectionFinding::query()
            ->where('validation_status', InspectionFinding::VALIDATION_DISETUJUI)
            ->whereNull('follow_up_work_order_id')
            ->when($suggestedTeamType !== null, fn (Builder $query) => $query->where('suggested_team_type', $suggestedTeamType))
            ->with(['inspection.penyulang', 'keypoint'])
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Pemeriksaan deterministik untuk tes dan pengaman pembaca antrean.
     * Tidak membuktikan bahwa suatu temuan aman untuk dibuatkan WO.
     */
    public static function matches(InspectionFinding $finding, ?string $suggestedTeamType = null, bool $fullOnly = false): bool
    {
        self::validateTeam($suggestedTeamType);

        if ($finding->validation_status !== InspectionFinding::VALIDATION_DISETUJUI
            || $finding->follow_up_work_order_id !== null) {
            return false;
        }

        if ($suggestedTeamType !== null && $finding->suggested_team_type !== $suggestedTeamType) {
            return false;
        }

        // Deprecated $fullOnly never filters. No inspection subtype exists in the workflow.
        return true;
    }

    private static function validateTeam(?string $suggestedTeamType): void
    {
        if ($suggestedTeamType !== null && ! in_array($suggestedTeamType, self::SUGGESTED_TEAMS, true)) {
            throw new InvalidArgumentException('Saran tim harus POSKO, ROW, atau HARSUS.');
        }
    }
}
