<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Single source of truth for POSKO shift A/B/C.
 *
 * Jam shift FINAL (keputusan pengguna 28 Okt 2026), WIB, berurutan 24 jam tanpa jeda:
 * A 08:00–16:00, B 16:00–00:00, C 00:00–08:00. Satu-satunya definisi jam ada di SHIFTS.
 * Definisi tanggal kerja operasional Shift C masih TBD; window() memakai tanggal kalender
 * (Shift C tanggal D = 00:00–08:00 tanggal D).
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
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys(self::SHIFTS);
    }

    /**
     * Rentang waktu shift pada tanggal kalender WIB (shift C = 00:00–08:00 tanggal itu).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function window(CarbonInterface|string $date, string $code): array
    {
        $shift = self::SHIFTS[$code];
        $day = CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date,
            self::TIMEZONE,
        )->startOfDay();

        return [
            'start' => $day->addHours($shift['start_hour']),
            'end' => $day->addHours($shift['end_hour']),
        ];
    }

    public function today(?CarbonInterface $now = null): string
    {
        return ($now ?? Carbon::now())->copy()->setTimezone(self::TIMEZONE)->toDateString();
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
