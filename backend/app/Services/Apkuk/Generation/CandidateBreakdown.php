<?php

namespace App\Services\Apkuk\Generation;

use App\Services\ApkukCandidate;
use App\Services\ApkukCandidateService;
use Illuminate\Support\Collection;

/**
 * Rincian kategori seluruh laporan APKUK pada satu eksekusi generator, agar Admin tahu mengapa
 * laporan tidak menjadi WO: sah dan terpetakan ke Posko, sah tetapi ditahan (Section–Posko tidak
 * terbukti), tidak sah (alasan ApkukCandidateService), atau histori yang diarsipkan.
 *
 * Satu laporan tidak sah dapat memiliki beberapa alasan. `not_eligible_by_primary` menghitung
 * setiap laporan sekali menurut PRIMARY_ORDER (jumlahnya = not_eligible); `issue_counts`
 * menghitung setiap alasan apa adanya.
 */
final class CandidateBreakdown
{
    /** Kondisi operasional lebih dulu (antrean rekonsiliasi, WO aktif), lalu laporan usang, lalu mutu data. */
    public const PRIMARY_ORDER = [
        ApkukCandidateService::ISSUE_MENUNGGU_REKONSILIASI,
        ApkukCandidateService::ISSUE_WO_AKTIF,
        ApkukCandidateService::ISSUE_ADA_LAPORAN_LEBIH_BARU,
        ApkukCandidateService::ISSUE_LAPORAN_GANDA_AMBIGU,
        ApkukCandidateService::ISSUE_TIDAK_ADA_KEBUTUHAN,
        ApkukCandidateService::ISSUE_STATUS_BERTENTANGAN,
        ApkukCandidateService::ISSUE_STATUS_TIDAK_DIKENAL,
        ApkukCandidateService::ISSUE_KURANG_KOSONG,
        ApkukCandidateService::ISSUE_KURANG_NEGATIF,
        ApkukCandidateService::ISSUE_PENYULANG_TIDAK_DIKENAL,
        ApkukCandidateService::ISSUE_NO_TIANG_TIDAK_VALID,
    ];

    /**
     * @param  Collection<int, ApkukCandidate>  $candidates  hasil ApkukCandidateService::evaluate()
     * @param  array<string, int>  $mappedByPosko  nama Posko => kandidat sah terpetakan
     * @param  array<int, array<string, mixed>>  $held  kandidat sah yang ditahan (berisi `reason`)
     * @return array<string, mixed>
     */
    public static function build(Collection $candidates, array $mappedByPosko, array $held, int $archived): array
    {
        $primary = array_fill_keys(self::PRIMARY_ORDER, 0);
        $issues = [];
        foreach ($candidates->reject(fn (ApkukCandidate $candidate) => $candidate->isEligible()) as $candidate) {
            $code = collect(self::PRIMARY_ORDER)->first(fn (string $issue) => in_array($issue, $candidate->issues, true)) ?? $candidate->issues[0];
            $primary[$code] = ($primary[$code] ?? 0) + 1;
            foreach ($candidate->issues as $issue) {
                $issues[$issue] = ($issues[$issue] ?? 0) + 1;
            }
        }
        arsort($issues);

        $heldByReason = collect($held)->countBy('reason')->sortDesc()->all();
        $eligible = $candidates->filter(fn (ApkukCandidate $candidate) => $candidate->isEligible())->count();

        return [
            'reports_active' => $candidates->count(),
            'reports_archived' => $archived,
            'eligible' => $eligible,
            'eligible_mapped' => array_sum($mappedByPosko),
            'eligible_mapped_by_posko' => $mappedByPosko,
            'eligible_held' => count($held),
            'eligible_held_by_reason' => $heldByReason,
            'not_eligible' => $candidates->count() - $eligible,
            'not_eligible_by_primary' => array_filter($primary),
            'issue_counts' => $issues,
        ];
    }
}
