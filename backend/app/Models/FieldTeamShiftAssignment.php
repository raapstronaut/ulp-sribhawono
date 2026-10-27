<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan shift tim POSKO berbatas waktu. Petugas tetap di timnya; shift tim dapat berganti.
 */
class FieldTeamShiftAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'field_team_id',
        'shift_code',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'field_team_id');
    }
}
