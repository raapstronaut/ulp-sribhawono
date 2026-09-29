<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VegetasiSourceRow extends Model
{
    protected $fillable = [
        'id_pohon_raw',
        'nama_pohon_raw',
        'penyulang_raw',
        'id_aset_raw',
        'nama_tiang_raw',
        'jenis_pohon_raw',
        'latitude_raw',
        'longitude_raw',
        'jarak_pohon_raw',
        'kondisi_raw',
        'tanggal_survey_raw',
        'section_id_raw',
        'keterangan_m_raw',
        'status_raw',
        'tanggal_inspeksi_terakhir_raw',
        'tanggal_row_terakhir_raw',
        'jenis_row_terakhir_raw',
        'kondisi_terakhir_agustus_raw',
        'foto_ref_raw',
        'keterangan_t_raw',
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
