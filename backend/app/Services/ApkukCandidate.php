<?php

namespace App\Services;

use App\Models\Apkuk;
use App\Models\Penyulang;

/**
 * Hasil penilaian satu baris apkuks sebagai calon lokasi WO APKUK.
 */
final class ApkukCandidate
{
    /**
     * @param  array<int, string>  $issues  Kode alasan yang membuat baris tidak dapat dipilih.
     * @param  array<int, string>  $warnings  Catatan kualitas data yang tidak memblokir.
     */
    public function __construct(
        public readonly Apkuk $apkuk,
        public readonly string $targetKey,
        public readonly string $normalizedPole,
        public readonly ?Penyulang $penyulang,
        public readonly ?string $sectionName,
        public readonly array $issues,
        public readonly array $warnings,
        public readonly ?string $activeWorkOrderCode,
    ) {}

    public function isEligible(): bool
    {
        return $this->issues === [];
    }

    public function targetQuantity(): int
    {
        return (int) $this->apkuk->kurang_bh;
    }

    /**
     * @return array<int, string>
     */
    public function issueLabels(): array
    {
        return array_map(fn (string $code) => ApkukCandidateService::LABELS[$code] ?? $code, $this->issues);
    }

    /**
     * @return array<int, string>
     */
    public function warningLabels(): array
    {
        return array_map(fn (string $code) => ApkukCandidateService::LABELS[$code] ?? $code, $this->warnings);
    }
}
