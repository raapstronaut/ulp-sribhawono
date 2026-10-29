<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Single source of truth for POSKO shift A/B/C.
 *
 * Keputusan final pengguna (28–29 Okt 2026), WIB, berurutan 24 jam tanpa jeda:
 * - Hari operasional D dimulai 08:00 WIB tanggal D dan berakhir 08:00 WIB tanggal D+1.
 * - Shift A = D 08:00–16:00, B = D 16:00–(D+1) 00:00, C = (D+1) 00:00–08:00.
 *   Ketiganya ber-operational_date D; Shift C setelah tengah malam milik hari operasional sebelumnya.
 * - Interval half-open [start, end): 00:00, 08:00, dan 16:00 hanya milik satu shift.
 *
 * Kolom work_date pada WO ber-shift adalah tanggal operasional. Satu-satunya definisi jam ada di SHIFTS.
 */
class ShiftService
{
    public const TIMEZONE = 'Asia/Jakarta';

    /** Jam mulai hari operasional (WIB). */
    public const OPERATIONAL_DAY_START_HOUR = 8;

    /**
     * Urutan sesuai hari operasional. start_hour inklusif, end_hour eksklusif (24 = tengah malam);
     * offset = jam sejak awal hari operasional.
     *
     * @var array<string, array{name: string, start_hour: int, end_hour: int, offset: int}>
     */
    private const SHIFTS = [
        'A' => ['name' => 'Pagi', 'start_hour' => 8, 'end_hour' => 16, 'offset' => 0],
        'B' => ['name' => 'Sore', 'start_hour' => 16, 'end_hour' => 24, 'offset' => 8],
        'C' => ['name' => 'Malam', 'start_hour' => 0, 'end_hour' => 8, 'offset' => 16],
    ];

    private const SHIFT_HOURS = 8;

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
     * Tanggal operasional (Y-m-d) pada saat tertentu: sebelum 08:00 WIB masih hari operasional kemarin.
     */
    public function operationalDate(?CarbonInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? Carbon::now())->setTimezone(self::TIMEZONE);

        return ($now->hour < self::OPERATIONAL_DAY_START_HOUR ? $now->subDay() : $now)->toDateString();
    }

    /**
     * Identitas shift yang sedang berjalan pada saat tertentu.
     *
     * @return array{operational_date: string, code: string, start: CarbonImmutable, end: CarbonImmutable}
     */
    public function instanceAt(?CarbonInterface $now = null): array
    {
        $date = $this->operationalDate($now);
        $code = $this->current($now)['code'];

        return ['operational_date' => $date, 'code' => $code] + $this->window($date, $code);
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_keys(self::SHIFTS);
    }

    /**
     * Rentang waktu [start, end) shift pada tanggal operasional (WIB).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function window(CarbonInterface|string $operationalDate, string $code): array
    {
        $start = CarbonImmutable::parse(
            $operationalDate instanceof CarbonInterface ? $operationalDate->format('Y-m-d') : $operationalDate,
            self::TIMEZONE,
        )->startOfDay()->addHours(self::OPERATIONAL_DAY_START_HOUR + self::SHIFTS[$code]['offset']);

        return ['start' => $start, 'end' => $start->addHours(self::SHIFT_HOURS)];
    }

    /**
     * Tanggal kalender WIB. Untuk pekerjaan ber-shift gunakan operationalDate().
     */
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
