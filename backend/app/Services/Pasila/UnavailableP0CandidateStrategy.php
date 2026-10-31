<?php

namespace App\Services\Pasila;

use App\Models\PasilaSession;

/**
 * Penanda bahwa usulan P0 SYSTEM otomatis belum siap: klasifikasi kode temuan yang memerlukan P0
 * dan bobot prioritas resmi belum tersedia. Tidak pernah mengusulkan apa pun; P0 dibuat lewat jalur
 * manual Admin sampai strategi berbasis data resmi tersedia.
 */
final class UnavailableP0CandidateStrategy implements P0CandidateStrategy
{
    public function name(): string
    {
        return 'BELUM_SIAP';
    }

    public function version(): string
    {
        return '-';
    }

    public function isMachineLearning(): bool
    {
        return false;
    }

    public function notReadyReason(): ?string
    {
        return 'Usulan P0 SYSTEM otomatis belum siap: klasifikasi kode temuan yang memerlukan P0 dan bobot prioritas resmi belum tersedia. Gunakan P0 manual Admin dari sumber yang tercatat.';
    }

    public function propose(PasilaSession $session, array $sources): array
    {
        return [];
    }
}
