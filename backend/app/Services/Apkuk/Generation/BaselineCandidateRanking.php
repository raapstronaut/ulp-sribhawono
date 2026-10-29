<?php

namespace App\Services\Apkuk\Generation;

use App\Models\ShiftInstance;
use App\Services\ApkukCandidate;
use Illuminate\Support\Collection;

/**
 * Baseline rule-based (BUKAN machine learning), deterministik dan dapat dijelaskan:
 * kebutuhan APKUK terbesar (kurang_bh) lebih dulu, lalu laporan tertua, lalu no. tiang dan ID
 * laporan sebagai pemecah seri. Sama dengan urutan daftar kandidat WO manual. Dipakai sebagai
 * pembanding untuk model ML kelak; tidak pernah diizinkan membuat WO di luar local/testing.
 */
final class BaselineCandidateRanking implements CandidateRanking
{
    public function name(): string
    {
        return 'BASELINE_KEBUTUHAN_TERTUA';
    }

    public function version(): string
    {
        return 'b2.1';
    }

    public function isMachineLearning(): bool
    {
        return false;
    }

    public function rank(Collection $candidates, ShiftInstance $shift): array
    {
        return $candidates
            ->sortBy([
                fn (ApkukCandidate $a, ApkukCandidate $b) => ($b->apkuk->kurang_bh ?? 0) <=> ($a->apkuk->kurang_bh ?? 0),
                fn (ApkukCandidate $a, ApkukCandidate $b) => (string) $a->apkuk->tanggal?->toDateString() <=> (string) $b->apkuk->tanggal?->toDateString(),
                fn (ApkukCandidate $a, ApkukCandidate $b) => $a->normalizedPole <=> $b->normalizedPole,
                fn (ApkukCandidate $a, ApkukCandidate $b) => $a->apkuk->id <=> $b->apkuk->id,
            ])
            ->values()
            ->map(fn (ApkukCandidate $candidate, int $index) => new RankedCandidate($candidate, $index + 1, null, [
                'aturan' => 'Baseline rule-based: kebutuhan APKUK terbesar, lalu laporan tertua (bukan ML)',
                'kurang_bh' => $candidate->apkuk->kurang_bh,
                'tanggal_laporan' => $candidate->apkuk->tanggal?->toDateString(),
            ]))
            ->all();
    }
}
