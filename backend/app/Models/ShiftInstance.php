<?php

namespace App\Models;

use App\Services\ShiftService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Satu shift (A/B/C) dalam hari operasional. Lihat ShiftService untuk definisi waktu.
 */
class ShiftInstance extends Model
{
    protected $fillable = [
        'operational_date',
        'shift_code',
        'starts_at',
        'ends_at',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'operational_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Ambil atau buat identitas shift; aman dipanggil berulang/bersamaan (unique tanggal + kode).
     */
    public static function ensure(ShiftService $shifts, string $operationalDate, string $shiftCode): self
    {
        $find = fn () => self::query()->whereDate('operational_date', $operationalDate)->where('shift_code', $shiftCode)->first();
        if ($existing = $find()) {
            return $existing;
        }

        $window = $shifts->window($operationalDate, $shiftCode);
        try {
            return self::create([
                'operational_date' => $operationalDate,
                'shift_code' => $shiftCode,
                'starts_at' => $window['start']->utc(),
                'ends_at' => $window['end']->utc(),
                'timezone' => ShiftService::TIMEZONE,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(ApkukGenerationRun::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApkukShiftAllocation::class);
    }

    public function label(): string
    {
        return 'Shift '.$this->shift_code.' · hari operasional '.$this->operational_date->format('d/m/Y');
    }
}
