<?php

namespace App\Services;

use App\Models\FieldTeam;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class PoskoCrewRotationService
{
    /** @var array<int, array{A:int, B:int, C:int, off:int}> */
    private const CYCLE = [
        ['A' => 1, 'B' => 2, 'C' => 3, 'off' => 4],
        ['A' => 4, 'B' => 1, 'C' => 2, 'off' => 3],
        ['A' => 3, 'B' => 4, 'C' => 1, 'off' => 2],
        ['A' => 2, 'B' => 3, 'C' => 4, 'off' => 1],
    ];

    public function __construct(private readonly ShiftService $shifts) {}

    /**
     * Rotasi berdasarkan tanggal OPERASIONAL, bukan tanggal kalender.
     * Null berarti tanggal acuan belum diset; jangan mengarang tim bertugas.
     *
     * @return array{cycle_day:int, crew_number:int, off_crew_number:int}|null
     */
    public function forDate(string $operationalDate, string $shiftCode): ?array
    {
        $anchor = config('field_ops.posko_crew_anchor_date');
        if (! is_string($anchor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
            return null;
        }
        if (! in_array($shiftCode, $this->shifts->codes(), true)) {
            return null;
        }

        try {
            $base = CarbonImmutable::parse($anchor, ShiftService::TIMEZONE)->startOfDay();
            if ($base->toDateString() !== $anchor) {
                return null;
            }
            $date = CarbonImmutable::parse($operationalDate, ShiftService::TIMEZONE)->startOfDay();
            if ($date->toDateString() !== $operationalDate) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $days = (int) $base->diffInDays($date, false);
        $index = (($days % 4) + 4) % 4;
        $cycle = self::CYCLE[$index];

        return [
            'cycle_day' => $index + 1,
            'crew_number' => $cycle[$shiftCode],
            'off_crew_number' => $cycle['off'],
        ];
    }

    /** @return array<string, mixed> */
    public function current(?CarbonInterface $now = null): array
    {
        $shift = $this->shifts->instanceAt($now);
        $rotation = $this->forDate($shift['operational_date'], $shift['code']);

        return [
            'configured' => $rotation !== null,
            'operational_date' => $shift['operational_date'],
            'shift_code' => $shift['code'],
            'cycle_day' => $rotation['cycle_day'] ?? null,
            'crew_number' => $rotation['crew_number'] ?? null,
            'off_crew_number' => $rotation['off_crew_number'] ?? null,
        ];
    }

    /**
     * Tim POSKO induk memiliki tepat empat pasangan; jika roster belum lengkap,
     * aplikasi mendapat is_complete=false dan tidak boleh memakai hasil sebagai
     * penugasan resmi.
     *
     * @return array<string, mixed>
     */
    public function roster(FieldTeam $team, ?CarbonInterface $now = null): array
    {
        $rotation = $this->current($now);
        $team->loadMissing('personnel');
        $activePersonnel = $team->personnel->where('is_active', true);
        $crews = [];

        foreach (range(1, 4) as $number) {
            $members = $activePersonnel->filter(fn ($person) => (int) $person->crew_number === $number)
                ->sortBy('name')->values()
                ->map(fn ($person) => [
                    'id' => $person->getKey(),
                    'name' => $person->name,
                    'nickname' => $person->nickname,
                ])->all();

            $crews[] = [
                'number' => $number,
                'label' => 'Tim '.$number.' — '.implode(' & ', array_map(
                    fn (array $person) => $person['nickname'] ?: $person['name'],
                    $members,
                )),
                'is_complete' => count($members) === 2,
                'members' => $members,
            ];
        }

        $complete = count($activePersonnel) === 8
            && collect($crews)->every(fn (array $crew) => $crew['is_complete']);

        return [
            'crews' => $crews,
            'roster_complete' => $complete,
            'rotation' => $rotation,
            'current_crew_number' => $complete ? $rotation['crew_number'] : null,
        ];
    }
}
