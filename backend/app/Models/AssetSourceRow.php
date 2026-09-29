<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetSourceRow extends Model
{
    protected $fillable = [
        'id_aset_raw',
        'nama_aset_raw',
        'jenis_aset_raw',
        'penyulang_raw',
        'latitude_raw',
        'longitude_raw',
        'status_raw',
        'source_row_number',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'source_row_number' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
