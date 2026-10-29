<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil generate untuk satu Posko pada satu shift. Keberadaannya menandai alokasi sudah
 * dijalankan; unik per shift + Posko sehingga generator tidak mengisi kuota dua kali.
 */
class ApkukShiftAllocation extends Model
{
    protected $fillable = [
        'shift_instance_id',
        'posko_id',
        'generation_run_id',
        'target_count',
        'carry_over_count',
        'existing_count',
        'generated_count',
        'total_count',
        'shortfall_count',
        'overload_count',
        'generated_at',
    ];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function shiftInstance(): BelongsTo
    {
        return $this->belongsTo(ShiftInstance::class);
    }

    public function posko(): BelongsTo
    {
        return $this->belongsTo(Posko::class);
    }

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(ApkukGenerationRun::class);
    }
}
