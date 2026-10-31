<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Petugas roster yang menyatakan laporan nihil PASILA.
 */
class PasilaNilReportPersonnel extends Model
{
    protected $table = 'pasila_nil_report_personnel';

    protected $fillable = [
        'pasila_nil_report_id',
        'field_personnel_id',
        'personnel_name',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(PasilaNilReport::class, 'pasila_nil_report_id');
    }
}
