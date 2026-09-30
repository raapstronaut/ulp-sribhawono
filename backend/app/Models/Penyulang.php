<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Penyulang extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kode_penyulang',
        'nama_penyulang',
        'gardu_induk',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi many-to-many ke master Posko.
     */
    public function poskos(): BelongsToMany
    {
        return $this->belongsToMany(Posko::class, 'posko_penyulang', 'penyulang_id', 'posko_id')
            ->withTimestamps();
    }
}
