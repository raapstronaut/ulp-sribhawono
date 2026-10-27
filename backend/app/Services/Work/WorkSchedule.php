<?php

namespace App\Services\Work;

use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Menghitung jadwal WO: WO POSKO mengikuti jendela shift; WO HARSUS/ROW mengikuti
 * tanggal kerja (mulai 00:00 WIB) dan tanggal target selesai (23:59:59 WIB).
 */
class WorkSchedule
{
    public function __construct(private readonly ShiftService $shifts) {}

    /**
     * @return array{scheduled_start_at: CarbonImmutable, due_at: CarbonImmutable}
     */
    public function forShift(string $workDate, string $shiftCode, ?CarbonImmutable $now = null, string $field = 'shift_code'): array
    {
        if (! in_array($shiftCode, $this->shifts->codes(), true)) {
            throw ValidationException::withMessages([$field => 'Shift tidak dikenal.']);
        }

        $window = $this->shifts->window($workDate, $shiftCode);
        if ($window['end']->lte($now ?? CarbonImmutable::now())) {
            throw ValidationException::withMessages([$field => 'Shift yang dipilih sudah berakhir.']);
        }

        return [
            'scheduled_start_at' => $window['start']->utc(),
            'due_at' => $window['end']->utc(),
        ];
    }

    /**
     * @return array{scheduled_start_at: CarbonImmutable, due_at: CarbonImmutable}
     */
    public function forDates(string $workDate, string $dueDate, ?CarbonImmutable $now = null, string $field = 'due_date'): array
    {
        $start = CarbonImmutable::parse($workDate, ShiftService::TIMEZONE)->startOfDay();
        $due = CarbonImmutable::parse($dueDate, ShiftService::TIMEZONE)->endOfDay();

        if ($due->lt($start)) {
            throw ValidationException::withMessages([$field => 'Target selesai tidak boleh sebelum tanggal kerja.']);
        }
        if ($due->lte($now ?? CarbonImmutable::now())) {
            throw ValidationException::withMessages([$field => 'Target selesai sudah lewat.']);
        }

        return [
            'scheduled_start_at' => $start->utc(),
            'due_at' => $due->utc(),
        ];
    }
}
