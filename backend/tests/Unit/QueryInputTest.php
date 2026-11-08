<?php

namespace Tests\Unit;

use App\Support\QueryInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class QueryInputTest extends TestCase
{
    public function test_text_ignores_arrays_strips_control_characters_and_limits_length(): void
    {
        $request = Request::create('/x', 'GET', [
            'q' => "  ab\0c\x1F  ",
            'list' => ['a', 'b'],
            'long' => str_repeat('x', 150),
            'broken' => "a\xC3(",
            'key' => ' REOG ',
        ]);

        $this->assertSame('abc', QueryInput::text($request, 'q'));
        $this->assertSame('', QueryInput::text($request, 'list'));
        $this->assertSame('', QueryInput::text($request, 'missing'));
        $this->assertSame(100, mb_strlen(QueryInput::text($request, 'long')));
        $this->assertTrue(mb_check_encoding(QueryInput::text($request, 'broken'), 'UTF-8'));
        $this->assertSame(' REOG ', QueryInput::text($request, 'key', 255, trim: false));
    }

    public function test_choice_and_integer_choice_fall_back_to_default(): void
    {
        $request = Request::create('/x', 'GET', ['tab' => 'kode', 'bad' => 'drop table', 'period' => '30', 'odd' => '30abc', 'arr' => ['30']]);

        $this->assertSame('kode', QueryInput::choice($request, 'tab', ['posko', 'kode'], 'posko'));
        $this->assertSame('posko', QueryInput::choice($request, 'bad', ['posko', 'kode'], 'posko'));
        $this->assertSame(30, QueryInput::integerChoice($request, 'period', [30, 90], 90));
        $this->assertSame(90, QueryInput::integerChoice($request, 'odd', [30, 90], 90));
        $this->assertSame(90, QueryInput::integerChoice($request, 'arr', [30, 90], 90));
    }

    public function test_uuid_and_date_only_accept_values_postgresql_can_compare(): void
    {
        $request = Request::create('/x', 'GET', [
            'id' => '9d3b1a4e-0000-4000-8000-000000000000',
            'bad_id' => 'bukan-uuid',
            'padded_id' => '9d3b1a4e-0000-4000-8000-000000000000ffff',
            'date' => '2026-10-20',
            'month_13' => '2026-13-45',
            'feb_31' => '2026-02-31',
            'iso_time' => '2026-10-20T08:00',
        ]);

        $this->assertSame('9d3b1a4e-0000-4000-8000-000000000000', QueryInput::uuid($request, 'id'));
        $this->assertSame('', QueryInput::uuid($request, 'bad_id'));
        $this->assertSame('', QueryInput::uuid($request, 'padded_id'));
        $this->assertSame('2026-10-20', QueryInput::date($request, 'date'));
        $this->assertSame('', QueryInput::date($request, 'month_13'));
        $this->assertSame('', QueryInput::date($request, 'feb_31'));
        $this->assertSame('', QueryInput::date($request, 'iso_time'));
    }
}
