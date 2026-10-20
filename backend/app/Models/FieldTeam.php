<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun tim lapangan (POSKO/HARSUS/INSPEKSI/ROW), terpisah dari petugas.
 */
class FieldTeam extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'role',
        'posko_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function posko(): BelongsTo
    {
        return $this->belongsTo(Posko::class);
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(FieldPersonnel::class);
    }
}
