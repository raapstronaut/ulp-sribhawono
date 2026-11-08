<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Posko;
use App\Models\ShiftInstance;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DashboardService;
use App\Services\ShiftService;
use App\Services\Work\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jam shift FINAL (A 08–16, B 16–00, C 00–08 WIB) dan hari operasional (keputusan pengguna
 * 29 Okt 2026): hari operasional D = 08:00 WIB D sampai 08:00 WIB D+1, Shift C setelah tengah
 * malam ber-tanggal operasional D, interval half-open [start, end).
 */
class ShiftBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function utcInstants(): array
    {
        // [UTC, shift, tanggal kalender WIB, tanggal operasional]
        return [
            '07:59:59 WIB' => ['2026-10-28 00:59:59', 'C', '2026-10-28', '2026-10-27'],
            '08:00 WIB' => ['2026-10-28 01:00:00', 'A', '2026-10-28', '2026-10-28'],
            '15:59:59 WIB' => ['2026-10-28 08:59:59', 'A', '2026-10-28', '2026-10-28'],
            '16:00 WIB' => ['2026-10-28 09:00:00', 'B', '2026-10-28', '2026-10-28'],
            '23:59 WIB' => ['2026-10-28 16:59:00', 'B', '2026-10-28', '2026-10-28'],
            '23:59:59 WIB' => ['2026-10-28 16:59:59', 'B', '2026-10-28', '2026-10-28'],
            '00:00 WIB hari berikutnya' => ['2026-10-28 17:00:00', 'C', '2026-10-29', '2026-10-28'],
        ];
    }

    #[DataProvider('utcInstants')]
    public function test_shift_and_operational_date_at_boundaries_do_not_drift(string $utc, string $code, string $calendarDate, string $operationalDate): void
    {
        $now = Carbon::parse($utc, 'UTC');
        $shifts = new ShiftService;

        $this->assertSame($code, $shifts->current($now)['code']);
        $this->assertSame($calendarDate, $shifts->today($now));
        $this->assertSame($operationalDate, $shifts->operationalDate($now));

        // Instan berada di dalam [start, end) shift-nya sendiri dan tidak di shift lain.
        $instance = $shifts->instanceAt($now);
        $this->assertSame([$operationalDate, $code], [$instance['operational_date'], $instance['code']]);
        $this->assertTrue($instance['start']->lte($now) && $now->lt($instance['end']));
    }

    public function test_three_shifts_of_an_operational_day_cover_24_hours_without_gap_or_overlap(): void
    {
        $shifts = new ShiftService;
        $a = $shifts->window('2026-10-28', 'A');
        $b = $shifts->window('2026-10-28', 'B');
        $c = $shifts->window('2026-10-28', 'C');
        $nextA = $shifts->window('2026-10-29', 'A');

        $this->assertSame('2026-10-28 08:00', $a['start']->format('Y-m-d H:i'));
        $this->assertTrue($a['end']->equalTo($b['start']));
        $this->assertSame('2026-10-29 00:00', $b['end']->format('Y-m-d H:i'), 'Shift B berakhir tepat tengah malam WIB.');
        $this->assertTrue($b['end']->equalTo($c['start']), 'Shift C hari operasional 28 dimulai 29 Okt 00:00.');
        $this->assertSame('2026-10-29 08:00', $c['end']->format('Y-m-d H:i'));
        $this->assertTrue($c['end']->equalTo($nextA['start']));
        foreach ([$a, $b, $c] as $window) {
            $this->assertSame(8.0, $window['start']->diffInHours($window['end']));
        }
        $this->assertSame(
            ['A' => '08:00–16:00 WIB', 'B' => '16:00–00:00 WIB', 'C' => '00:00–08:00 WIB'],
            collect($shifts->codes())->mapWithKeys(fn ($code) => [$code => $shifts->describe($code)['time']])->all(),
        );
    }

    public function test_work_schedule_stores_shift_windows_in_utc_and_closes_shift_b_at_midnight(): void
    {
        $schedule = app(WorkSchedule::class);
        $jakarta = fn (string $at) => CarbonImmutable::parse($at, ShiftService::TIMEZONE);

        $b = $schedule->forShift('2026-10-28', 'B', $jakarta('2026-10-28 23:59:00'));
        $this->assertSame('2026-10-28 09:00:00', $b['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 17:00:00', $b['due_at']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $b['due_at']->getTimezone()->getName());

        // Shift C hari operasional 28 = 29 Okt 00:00–08:00 WIB.
        $c = $schedule->forShift('2026-10-28', 'C', $jakarta('2026-10-28 23:59:00'));
        $this->assertSame('2026-10-28 17:00:00', $c['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-29 01:00:00', $c['due_at']->format('Y-m-d H:i:s'));

        $this->expectException(ValidationException::class);
        $schedule->forShift('2026-10-28', 'B', $jakarta('2026-10-29 00:00:00'));
    }

    public function test_dashboard_and_pasila_stay_on_the_operational_day_after_midnight(): void
    {
        $posko = Posko::create(['kode_posko' => 'PUGUNG', 'nama_posko' => 'PUGUNG']);
        $admin = User::factory()->admin()->create(['username' => 'SHIFT_ADMIN']);
        $workOrder = fn (string $code, string $date, string $shift) => WorkOrder::create([
            'code' => $code, 'team_type' => WorkOrder::TEAM_POSKO, 'work_type' => WorkOrder::TYPE_PEMASANGAN_APKUK,
            'source' => WorkOrder::SOURCE_ADMIN_MANUAL, 'status' => WorkOrderStatus::Ditugaskan, 'posko_id' => $posko->id,
            'work_date' => $date, 'shift_code' => $shift,
        ]);
        $workOrder('WO-B-28', '2026-10-28', 'B');
        $workOrder('WO-C-28', '2026-10-28', 'C');
        $workOrder('WO-C-29', '2026-10-29', 'C');

        $this->travelTo(Carbon::create(2026, 10, 28, 23, 59, 0, ShiftService::TIMEZONE));
        $data = app(DashboardService::class)->getDashboardData();
        $this->assertSame(['B', '2026-10-28'], [$data['shiftData']['code'], $data['shiftData']['operational_date']]);
        $this->assertSame(1, $data['poskoStatuses'][0]['apkuk_total']);
        $pasila = $this->actingAs($admin)->get(route('admin.pasila.index'))->assertOk()->viewData('context');
        $this->assertSame(['2026-10-28', 'B'], [$pasila['work_date'], $pasila['shift_code']]);

        $this->travelTo(Carbon::create(2026, 10, 29, 0, 0, 0, ShiftService::TIMEZONE));
        $data = app(DashboardService::class)->getDashboardData();
        $this->assertSame(['C', '2026-10-28'], [$data['shiftData']['code'], $data['shiftData']['operational_date']]);
        $this->assertSame(1, $data['poskoStatuses'][0]['apkuk_total'], 'Hanya WO Shift C hari operasional 28 yang dihitung.');
        $pasila = $this->actingAs($admin)->get(route('admin.pasila.index'))->assertOk()->viewData('context');
        $this->assertSame(['2026-10-28', 'C'], [$pasila['work_date'], $pasila['shift_code']]);
        $this->assertTrue($pasila['is_current']);

        $this->travelTo(Carbon::create(2026, 10, 29, 8, 0, 0, ShiftService::TIMEZONE));
        $this->assertSame(['A', '2026-10-29'], [app(ShiftService::class)->current()['code'], app(ShiftService::class)->operationalDate()]);
    }

    public function test_shift_instance_identity_is_stable_and_never_duplicated(): void
    {
        $shifts = new ShiftService;
        $first = ShiftInstance::ensure($shifts, '2026-10-28', 'C');
        $again = ShiftInstance::ensure($shifts, '2026-10-28', 'C');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ShiftInstance::query()->count());
        $this->assertSame('2026-10-28 17:00:00', $first->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-29 01:00:00', $first->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(ShiftService::TIMEZONE, $first->timezone);

        // Unique tanggal operasional + kode menolak baris ganda walau ensure() dilewati.
        $this->expectException(UniqueConstraintViolationException::class);
        ShiftInstance::create(['operational_date' => '2026-10-28', 'shift_code' => 'C', 'starts_at' => now(), 'ends_at' => now(), 'timezone' => ShiftService::TIMEZONE]);
    }
}
