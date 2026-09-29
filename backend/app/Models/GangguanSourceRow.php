<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GangguanSourceRow extends Model
{
    protected $fillable = [
        'id_gangguan_raw',
        'penyulang_raw',
        'peralatan_raw',
        'ulp_raw',
        'tanggal_kejadian_raw',
        'kode_gangguan_raw',
        'uraian_penyebab_raw',
        'penyebab_raw',
        'lepas_raw',
        'masuk_raw',
        'beban_amp_raw',
        'rele_kerja_raw',
        'arus_gangguan_amp_raw',
        'lama_padam_menit_raw',
        'ens_kwh_raw',
        'sifat_gangguan_raw',
        'id_aset_raw',
        'section_id_raw',
        'posko_id_raw',
        'latitude_raw',
        'longitude_raw',
        'jenis_hewan_raw',
        'foto_ref_raw',
        'sumber_data_raw',
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
