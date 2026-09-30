<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Posko extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kode_posko',
        'nama_posko',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi many-to-many ke master Penyulang.
     */
    public function penyulangs(): BelongsToMany
    {
        return $this->belongsToMany(Penyulang::class, 'posko_penyulang', 'posko_id', 'penyulang_id')
            ->withTimestamps();
    }
}
