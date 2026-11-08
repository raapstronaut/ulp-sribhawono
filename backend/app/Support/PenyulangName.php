<?php

namespace App\Support;

use App\Models\Penyulang;

/**
 * Normalisasi nama penyulang dari sumber (SPS BOS, Gangguan PLN pusat, staging) ke nama master.
 *
 * Nilai mentah di tabel sumber tidak pernah diubah; normalisasi hanya dipakai saat membaca untuk
 * join, filter, dan agregasi. Yang dinormalisasi hanya kapital, spasi tepi, dan titik di akhir
 * ("CAKALELE." → CAKALELE), ditambah alias yang sudah dikonfirmasi pengguna. Penyulang lain tidak
 * ditebak.
 */
final class PenyulangName
{
    /**
     * Alias terkonfirmasi (keputusan pengguna 28 Okt 2026): nama sumber ternormalisasi → nama master.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'TANGGAMUS' => 'TANGGAMUS 2',
    ];

    public static function normalize(?string $value): string
    {
        $text = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));

        return trim(rtrim($text, '.'));
    }

    public static function canonical(?string $value): string
    {
        $normalized = self::normalize($value);

        return self::ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Ekspresi SQL (PostgreSQL dan SQLite) untuk nama ternormalisasi tanpa alias.
     */
    public static function sqlNormalized(string $column): string
    {
        return "upper(rtrim(trim({$column}), '.'))";
    }

    /**
     * Ekspresi SQL untuk nama canonical (normalisasi + alias), untuk GROUP BY dan filter agregat.
     */
    public static function sqlCanonical(string $column): string
    {
        $normalized = self::sqlNormalized($column);
        $cases = collect(self::ALIASES)
            ->map(fn (string $target, string $alias) => "WHEN '{$alias}' THEN '{$target}'")
            ->implode(' ');

        return "CASE {$normalized} {$cases} ELSE {$normalized} END";
    }

    /**
     * Semua penulisan sumber (ternormalisasi) yang merujuk penyulang master ini, termasuk alias.
     *
     * @return array<int, string>
     */
    public static function sourceNames(Penyulang $penyulang): array
    {
        return self::spellings(self::normalize($penyulang->kode_penyulang), self::normalize($penyulang->nama_penyulang));
    }

    /**
     * Nama ternormalisasi beserta alias sumber yang merujuk ke nama-nama tersebut.
     *
     * @return array<int, string>
     */
    public static function spellings(string ...$names): array
    {
        $names = array_values(array_filter($names, fn (string $name) => $name !== ''));
        foreach (self::ALIASES as $alias => $target) {
            if (in_array($target, $names, true)) {
                $names[] = $alias;
            }
        }

        return array_values(array_unique($names));
    }
}
