<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Petugas (manusia) pada roster tim; bukan akun login.
 */
class FieldPersonnel extends Model
{
    use HasUuids;

    protected $table = 'field_personnel';

    protected $fillable = [
        'field_team_id',
        'personnel_code',
        'name',
        'nickname',
        'crew_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'crew_number' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'field_team_id');
    }
}
