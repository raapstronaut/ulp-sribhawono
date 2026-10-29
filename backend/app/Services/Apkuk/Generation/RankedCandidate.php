<?php

namespace App\Services\Apkuk\Generation;

use App\Services\ApkukCandidate;

/**
 * Kandidat beserta peringkat dan alasan pemilihannya (dicatat pada eksekusi dan log WO).
 */
final class RankedCandidate
{
    /**
     * @param  float|null  $score  skor model; null untuk strategi aturan tanpa skor probabilistik
     * @param  array<string, mixed>  $reasons
     */
    public function __construct(
        public readonly ApkukCandidate $candidate,
        public readonly int $rank,
        public readonly ?float $score,
        public readonly array $reasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $apkuk = $this->candidate->apkuk;

        return [
            'apkuk_id' => $apkuk->id,
            'source_id' => $apkuk->source_id,
            'no_tiang' => $apkuk->no_tiang,
            'penyulang' => $this->candidate->penyulang?->nama_penyulang,
            'section' => $this->candidate->sectionName,
            'section_origin' => $this->candidate->sectionOrigin,
            'kurang_bh' => $apkuk->kurang_bh,
            'tanggal_laporan' => $apkuk->tanggal?->toDateString(),
            'rank' => $this->rank,
            'score' => $this->score,
            'reasons' => $this->reasons,
        ];
    }
}
