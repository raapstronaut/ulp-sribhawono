<?php

namespace App\Services\Pasila;

use App\Models\PasilaSession;

/**
 * Strategi usulan P0 SYSTEM untuk satu sesi PASILA. Strategi hanya memilih di antara sumber P0 yang
 * sudah sah (P0Service::candidates: temuan tercatat milik Posko sesi); validasi sumber, Section, dan
 * Posko ditegakkan P0Service dan tidak dapat dilangkahi. Hasilnya selalu usulan MENUNGGU_ACC.
 * Dipilih lewat config('pasila.p0_strategy').
 */
interface P0CandidateStrategy
{
    public function name(): string;

    public function version(): string;

    /** True hanya untuk model ML yang benar-benar dilatih dan tervalidasi. */
    public function isMachineLearning(): bool;

    /** Alasan strategi belum boleh dipakai (data/bobot resmi belum ada); null bila siap. */
    public function notReadyReason(): ?string;

    /**
     * @param  array<int, array{source_type: string, source_id: string, label: string}>  $sources  sumber sah
     * @return array<int, array{source_type: string, source_id: string, description: string, reasons: array<int, string>}>
     */
    public function propose(PasilaSession $session, array $sources): array;
}
