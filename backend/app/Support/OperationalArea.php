<?php

namespace App\Support;

/**
 * Menilai apakah koordinat layak dipetakan di wilayah operasional ULP Sribhawono.
 * Hanya untuk tampilan peta; nilai koordinat di database dan sumber tidak diubah.
 */
final class OperationalArea
{
    public const ISSUE_ZERO = 'KOORDINAT_NOL';

    public const ISSUE_OUTSIDE = 'DI_LUAR_WILAYAH';

    public const ISSUE_LABELS = [
        self::ISSUE_ZERO => 'Latitude atau longitude bernilai 0 (GPS gagal atau belum terisi)',
        self::ISSUE_OUTSIDE => 'Di luar wilayah operasional ULP Sribhawono',
    ];

    /**
     * @return array{south: float, north: float, west: float, east: float}
     */
    public static function bounds(): array
    {
        return config('maps.operational_bounds');
    }

    /**
     * Kode masalah koordinat; null bila layak dipetakan.
     */
    public static function issue(float $latitude, float $longitude): ?string
    {
        if ($latitude === 0.0 || $longitude === 0.0) {
            return self::ISSUE_ZERO;
        }

        $bounds = self::bounds();
        $inside = $latitude >= $bounds['south'] && $latitude <= $bounds['north']
            && $longitude >= $bounds['west'] && $longitude <= $bounds['east'];

        return $inside ? null : self::ISSUE_OUTSIDE;
    }
}
