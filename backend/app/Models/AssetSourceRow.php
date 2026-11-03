<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot mentah ASSET_RAW. section_raw dan posko_raw adalah nilai sumber yang belum terverifikasi,
 * bukan referensi kepemilikan Section → Posko (lihat SectionPoskoMap).
 */
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
        'foto_raw',
        'section_raw',
        'posko_raw',
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
