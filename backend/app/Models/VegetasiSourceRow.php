<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VegetasiSourceRow extends Model
{
    protected $fillable = [
        'no_raw',
        'nama_pohon_raw',
        'up3_raw',
        'ulp_raw',
        'penyulang_raw',
        'id_jtm_raw',
        'id_tiang_raw',
        'nama_tiang_raw',
        'jenis_pohon_raw',
        'pertumbuhan_pohon_raw',
        'jarak_aman_raw',
        'jarak_bahaya_raw',
        'kondisi_raw',
        'section_raw',
        'eam_raw',
        'latitude_raw',
        'longitude_raw',
        'jarak_pohon_raw',
        'jarak_pohon_saat_ini_raw',
        'izin_padam_raw',
        'izin_tebang_bawah_raw',
        'tanggal_update_raw',
        'tanggal_periksa_raw',
        'tanggal_perkiraan_bahaya_raw',
        'user_pembuat_raw',
        'user_update_raw',
        'keterangan_raw',
        'id_pohon_raw',
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
