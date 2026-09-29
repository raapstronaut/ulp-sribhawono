<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApkukSourceRow extends Model
{
    protected $fillable = [
        'source_id',
        'tanggal',
        'petugas_raw',
        'penyulang_raw',
        'section_raw',
        'asset_code_raw',
        'naik_dari_raw',
        'terpasang_bh',
        'kurang_bh',
        'status_raw',
        'koordinat_raw',
        'latitude',
        'longitude',
        'foto_raw',
        'source_row_number',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'terpasang_bh' => 'integer',
            'kurang_bh' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'source_row_number' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}