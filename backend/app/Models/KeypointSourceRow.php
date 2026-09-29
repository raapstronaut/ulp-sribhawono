<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KeypointSourceRow extends Model
{
    protected $fillable = [
        'penyulang_raw',
        'jenis_raw',
        'nama_raw',
        'koordinat_raw',
        'note_raw',
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
