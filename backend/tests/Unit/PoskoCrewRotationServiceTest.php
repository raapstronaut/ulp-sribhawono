<?php

namespace Tests\Unit;

use App\Services\PoskoCrewRotationService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PoskoCrewRotationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('field_ops.posko_crew_anchor_date', '2026-11-09');
    }

    public function test_rotation_is_not_active_before_ninth_november(): void
    {
        $rotation = app(PoskoCrewRotationService::class);

        $this->assertNull($rotation->forDate('2026-11-08', 'A'));
        $this->assertFalse($rotation->current(CarbonImmutable::create(2026, 11, 9, 7, 59, 0, ShiftService::TIMEZONE))['configured']);
    }

    public function test_initial_four_day_cycle_and_repeat(): void
    {
        $rotation = app(PoskoCrewRotationService::class);
        $days = [
            '2026-11-09' => ['A' => 1, 'B' => 2, 'C' => 3, 'off' => 4],
            '2026-11-10' => ['A' => 4, 'B' => 1, 'C' => 2, 'off' => 3],
            '2026-11-11' => ['A' => 3, 'B' => 4, 'C' => 1, 'off' => 2],
            '2026-11-12' => ['A' => 2, 'B' => 3, 'C' => 4, 'off' => 1],
            '2026-11-13' => ['A' => 1, 'B' => 2, 'C' => 3, 'off' => 4],
        ];

        foreach ($days as $date => $shifts) {
            foreach (['A', 'B', 'C'] as $code) {
                $actual = $rotation->forDate($date, $code);
                $this->assertNotNull($actual, "$date $code");
                $this->assertSame($shifts[$code], $actual['crew_number'], "$date $code");
                $this->assertSame($shifts['off'], $actual['off_crew_number'], "$date $code");
            }
        }
    }

    public function test_midnight_belongs_to_previous_operational_day(): void
    {
        $rotation = app(PoskoCrewRotationService::class);

        $night = $rotation->current(CarbonImmutable::create(2026, 11, 10, 0, 30, 0, ShiftService::TIMEZONE));
        $this->assertSame('2026-11-09', $night['operational_date']);
        $this->assertSame('C', $night['shift_code']);
        $this->assertSame(3, $night['crew_number']);

        $morning = $rotation->current(CarbonImmutable::create(2026, 11, 10, 8, 0, 0, ShiftService::TIMEZONE));
        $this->assertSame('2026-11-10', $morning['operational_date']);
        $this->assertSame('A', $morning['shift_code']);
        $this->assertSame(4, $morning['crew_number']);
    }

    public function test_without_anchor_returns_unconfigured(): void
    {
        config()->set('field_ops.posko_crew_anchor_date', null);
        $rotation = app(PoskoCrewRotationService::class);
        $this->assertNull($rotation->forDate('2026-11-09', 'A'));
    }
}
