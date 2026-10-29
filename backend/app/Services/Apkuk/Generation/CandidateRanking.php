<?php

namespace App\Services\Apkuk\Generation;

use App\Models\ShiftInstance;
use App\Services\ApkukCandidate;
use Illuminate\Support\Collection;

/**
 * Strategi pengurutan kandidat WO APKUK yang sudah sah (kelayakan, pending, WO aktif, dan
 * pemetaan Posko ditegakkan di luar strategi dan tidak dapat dilangkahi).
 *
 * Implementasi saat ini hanya BaselineCandidateRanking (rule-based). Model ML kelak
 * mengimplementasikan kontrak ini dengan isMachineLearning() = true, version() = versi model
 * ber-versi, dan score/reasons dari inferensi; generator mencatat strategi, versi, peringkat,
 * dan alasan pada setiap eksekusi dan WO.
 */
interface CandidateRanking
{
    public function name(): string;

    public function version(): string;

    /**
     * True hanya untuk model yang benar-benar dilatih; baseline aturan selalu false. Hanya label
     * provenance, bukan bukti model tervalidasi dan bukan izin operasional (ApkukGeneratorGuard).
     */
    public function isMachineLearning(): bool;

    /**
     * @param  Collection<int, ApkukCandidate>  $candidates
     * @return array<int, RankedCandidate> terbaik lebih dulu, peringkat mulai 1
     */
    public function rank(Collection $candidates, ShiftInstance $shift): array;
}
