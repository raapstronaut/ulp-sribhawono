<?php

use App\Services\Pasila\RotationSectionStrategy;
use App\Services\Pasila\UnavailableP0CandidateStrategy;

/*
|--------------------------------------------------------------------------
| Sesi PASILA per Posko per shift (Batch B3.1)
|--------------------------------------------------------------------------
|
| Pembuka sesi (pasila:open-shift) hanya berjalan bila `opener.enabled` = true (default false),
| termasuk entri scheduler di routes/console.php. Railway saat ini tidak menjalankan
| schedule:run; aktivasi production adalah keputusan terpisah.
|
| `section_strategy`: kelas PasilaSectionStrategy untuk rekomendasi Section. Baseline rotasi
| (bukan skor risiko/ML) sampai bobot prioritas resmi tersedia.
|
*/

return [
    'opener' => [
        'enabled' => (bool) env('PASILA_OPENER_ENABLED', false),
    ],

    'section_strategy' => RotationSectionStrategy::class,

    // Strategi usulan P0 SYSTEM (B3.3). Belum siap: klasifikasi kode temuan dan bobot prioritas resmi
    // belum ada, sehingga tidak ada usulan otomatis; P0 dibuat lewat jalur manual Admin.
    'p0_strategy' => UnavailableP0CandidateStrategy::class,
];
