<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pembaca filter query string untuk halaman Admin.
 *
 * Nilai yang tidak valid diabaikan (dianggap filter kosong), bukan diteruskan ke SQL:
 * PostgreSQL menolak teks bukan UUID pada kolom uuid dan tanggal yang tidak ada,
 * sedangkan parameter berbentuk array (?q[]=x) memicu error konversi di PHP.
 */
final class QueryInput
{
    /**
     * @param  bool  $trim  false untuk nilai yang harus sama persis dengan data sumber.
     */
    public static function text(Request $request, string $key, int $max = 100, bool $trim = true): string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub($value, 'UTF-8')) ?? '';

        return mb_substr($trim ? trim($value) : $value, 0, $max);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function choice(Request $request, string $key, array $allowed, string $default = ''): string
    {
        $value = self::text($request, $key);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @param  array<int, int>  $allowed
     */
    public static function integerChoice(Request $request, string $key, array $allowed, int $default): int
    {
        // Batas panjang longgar agar nilai tidak terpotong menjadi valid sebelum diperiksa.
        $value = self::text($request, $key, 64);

        return ctype_digit($value) && in_array((int) $value, $allowed, true) ? (int) $value : $default;
    }

    public static function uuid(Request $request, string $key): string
    {
        $value = self::text($request, $key, 64);

        return Str::isUuid($value) ? $value : '';
    }

    /**
     * Tanggal Y-m-d yang benar-benar ada (2026-02-31 dan 2026-13-45 ditolak).
     */
    public static function date(Request $request, string $key): string
    {
        $value = self::text($request, $key, 64);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? $value : '';
    }
}
