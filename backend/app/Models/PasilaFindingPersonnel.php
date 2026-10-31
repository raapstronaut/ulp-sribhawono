<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Petugas roster yang melaporkan satu temuan PASILA (orang, bukan akun login).
 */
class PasilaFindingPersonnel extends Model
{
    protected $table = 'pasila_finding_personnel';

    protected $fillable = [
        'pasila_finding_id',
        'field_personnel_id',
        'personnel_name',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(PasilaFinding::class, 'pasila_finding_id');
    }
}
