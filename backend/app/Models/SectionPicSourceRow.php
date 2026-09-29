<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectionPicSourceRow extends Model
{
    protected $fillable = [
        'penyulang_raw',
        'pemutus_raw',
        'posko_pic_raw',
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
