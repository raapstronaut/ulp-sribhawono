<?php

namespace Tests\Unit;

use App\Services\ShiftService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShiftServiceTest extends TestCase
{
    /**
     * @return array<string, array{int, int, string}>
     */
    public static function shiftBoundaries(): array
    {
        return [
            'tengah malam' => [0, 0, 'C'],
            'akhir shift C' => [7, 59, 'C'],
            'awal shift A' => [8, 0, 'A'],
            'akhir shift A' => [15, 59, 'A'],
            'awal shift B' => [16, 0, 'B'],
            'akhir shift B' => [23, 59, 'B'],
        ];
    }

    #[DataProvider('shiftBoundaries')]
    public function test_current_shift_follows_jakarta_hour_boundaries(int $hour, int $minute, string $expectedCode): void
    {
        $now = Carbon::create(2026, 10, 19, $hour, $minute, 0, 'Asia/Jakarta');

        $this->assertSame($expectedCode, (new ShiftService)->current($now)['code']);
    }

    public function test_current_shift_converts_other_timezones_to_jakarta(): void
    {
        // 01:30 UTC = 08:30 WIB → shift A
        $now = Carbon::create(2026, 10, 19, 1, 30, 0, 'UTC');

        $this->assertSame('A', (new ShiftService)->current($now)['code']);
    }

    public function test_describe_returns_display_fields(): void
    {
        $service = new ShiftService;

        $this->assertSame(
            ['code' => 'A', 'name' => 'Pagi', 'time' => '08:00–16:00 WIB', 'index' => '1/3'],
            $service->describe('A'),
        );
        $this->assertSame('16:00–00:00 WIB', $service->describe('B')['time']);
        $this->assertSame('3/3', $service->describe('C')['index']);
    }

    public function test_window_returns_shift_bounds_on_calendar_date_in_jakarta(): void
    {
        $service = new ShiftService;
        $utc = fn ($dt) => $dt->utc()->format('Y-m-d H:i');

        $a = $service->window('2026-10-20', 'A');
        $this->assertSame('2026-10-20 01:00', $utc($a['start']));
        $this->assertSame('2026-10-20 09:00', $utc($a['end']));

        // Shift B ends at midnight WIB, i.e. the start of the next calendar day.
        $b = $service->window('2026-10-20', 'B');
        $this->assertSame('2026-10-21 00:00', $b['end']->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'));

        $c = $service->window(Carbon::create(2026, 10, 20, 23, 0, 0, 'Asia/Jakarta'), 'C');
        $this->assertSame('2026-10-19 17:00', $utc($c['start']));
        $this->assertSame('2026-10-20 01:00', $utc($c['end']));
    }

    public function test_today_uses_jakarta_calendar_date(): void
    {
        // 18:30 UTC = 01:30 WIB the next day.
        $this->assertSame('2026-10-20', (new ShiftService)->today(Carbon::create(2026, 10, 19, 18, 30, 0, 'UTC')));
        $this->assertSame(['A', 'B', 'C'], (new ShiftService)->codes());
    }
}
