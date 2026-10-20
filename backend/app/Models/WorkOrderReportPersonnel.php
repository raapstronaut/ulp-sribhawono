<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Petugas pelaksana pada laporan WO; nama disalin karena roster belum lengkap.
 */
class WorkOrderReportPersonnel extends Model
{
    protected $table = 'work_order_report_personnel';

    protected $fillable = [
        'work_order_report_id',
        'field_personnel_id',
        'personnel_name',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(WorkOrderReport::class, 'work_order_report_id');
    }

    public function fieldPersonnel(): BelongsTo
    {
        return $this->belongsTo(FieldPersonnel::class);
    }
}
