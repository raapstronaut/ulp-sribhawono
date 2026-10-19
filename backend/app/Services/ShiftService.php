<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Single source of truth for POSKO shift A/B/C.
 *
 * Jam shift masih SEMENTARA (belum dikunci bisnis): ubah hanya di SHIFTS.
 */
class ShiftService
{
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * Urutan sesuai rotasi harian. start_hour inklusif, end_hour eksklusif (24 = tengah malam).
     *
     * @var array<string, array{name: string, start_hour: int, end_hour: int}>
     */
    private const SHIFTS = [
        'A' => ['name' => 'Pagi', 'start_hour' => 8, 'end_hour' => 16],
        'B' => ['name' => 'Sore', 'start_hour' => 16, 'end_hour' => 24],
        'C' => ['name' => 'Malam', 'start_hour' => 0, 'end_hour' => 8],
    ];

    /**
     * @return array{code: string, name: string, time: string, index: string}
     */
    public function current(?CarbonInterface $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone(self::TIMEZONE);

        foreach (array_keys(self::SHIFTS) as $code) {
            $shift = self::SHIFTS[$code];

            if ($now->hour >= $shift['start_hour'] && $now->hour < $shift['end_hour']) {
                return $this->describe($code);
            }
        }

        // Tidak tercapai selama SHIFTS menutupi 0–24 jam penuh.
        return $this->describe('C');
    }

    /**
     * @return array{code: string, name: string, time: string, index: string}
     */
    public function describe(string $code): array
    {
        $shift = self::SHIFTS[$code];
        $position = array_search($code, array_keys(self::SHIFTS), true) + 1;

        return [
            'code' => $code,
            'name' => $shift['name'],
            'time' => sprintf(
                '%02d:00–%02d:00 WIB',
                $shift['start_hour'],
                $shift['end_hour'] % 24,
            ),
            'index' => $position.'/'.count(self::SHIFTS),
        ];
    }
}
