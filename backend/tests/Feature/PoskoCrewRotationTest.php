<?php

namespace Tests\Feature;

use App\Services\PoskoCrewRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PoskoCrewRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_crew_fields_are_additive_and_preserve_team_identity(): void
    {
        $this->assertTrue(Schema::hasColumns('field_personnel', ['field_team_id', 'name', 'nickname', 'crew_number']));
        $this->assertTrue(Schema::hasColumn('users', 'field_personnel_id'));
    }

    public function test_four_day_pattern_repeats_without_weekly_reset(): void
    {
        config()->set('field_ops.posko_crew_anchor_date', '2026-11-09');
        $service = app(PoskoCrewRotationService::class);

        $days = [
            ['2026-11-09', [1, 2, 3], 4],
            ['2026-11-10', [4, 1, 2], 3],
            ['2026-11-11', [3, 4, 1], 2],
            ['2026-11-12', [2, 3, 4], 1],
            ['2026-11-13', [1, 2, 3], 4],
        ];

        foreach ($days as [$date, $expected, $off]) {
            foreach (['A', 'B', 'C'] as $index => $shift) {
                $rotation = $service->forDate($date, $shift);
                $this->assertSame($expected[$index], $rotation['crew_number']);
                $this->assertSame($off, $rotation['off_crew_number']);
            }
        }
    }

    public function test_midnight_shift_c_belongs_to_previous_operational_date(): void
    {
        config()->set('field_ops.posko_crew_anchor_date', '2026-11-09');
        $state = app(PoskoCrewRotationService::class)->current(
            CarbonImmutable::parse('2026-11-10 02:30:00', 'Asia/Jakarta'),
        );

        $this->assertSame('2026-11-09', $state['operational_date']);
        $this->assertSame('C', $state['shift_code']);
        $this->assertSame(3, $state['crew_number']);
    }

    public function test_unconfigured_anchor_does_not_invent_assignment(): void
    {
        config()->set('field_ops.posko_crew_anchor_date', null);
        $this->assertNull(app(PoskoCrewRotationService::class)->forDate('2026-11-09', 'A'));
        $this->assertFalse(app(PoskoCrewRotationService::class)->current()['configured']);
    }
}
