<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Petugas aktual yang melakukan kunjungan inspeksi (orang, bukan akun login).
 */
class InspectionPersonnel extends Model
{
    protected $table = 'inspection_personnel';

    protected $fillable = [
        'inspection_id',
        'field_personnel_id',
        'personnel_name',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }
}
