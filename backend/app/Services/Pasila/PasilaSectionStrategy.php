<?php

namespace App\Services\Pasila;

use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ShiftInstance;

/**
 * Strategi rekomendasi Section PASILA untuk satu Posko pada satu shift.
 *
 * Strategi hanya memilih di antara Section terverifikasi milik Posko (SectionPoskoMap); kepemilikan
 * dan validasi tidak dapat dilangkahi. Implementasi dipilih lewat config('pasila.section_strategy'),
 * sehingga bobot prioritas resmi kelak cukup ditambahkan sebagai strategi baru.
 */
interface PasilaSectionStrategy
{
    public function name(): string;

    public function version(): string;

    /**
     * @param  array<int, array{key: string, section: string, penyulang: string, penyulang_model: ?Penyulang, posko: ?Posko, reason: ?string, posko_pic: array<int, string>}>  $sections  tidak kosong
     * @return array{entry: array<string, mixed>, reasons: array<int, string>}
     */
    public function recommend(Posko $posko, array $sections, ShiftInstance $shift): array;
}
