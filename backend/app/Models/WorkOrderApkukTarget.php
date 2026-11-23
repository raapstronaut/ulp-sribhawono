<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot target tiang APKUK saat WO dibuat. Nilai apkuks dapat berubah
 * oleh sinkronisasi, sehingga target WO tidak membaca ulang sumber.
 */
class WorkOrderApkukTarget extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_order_id',
        'apkuk_id',
        'inspection_finding_id',
        'target_key',
        'active_target_key',
        'no_tiang',
        'penyulang_source',
        'section_source',
        'naik_dari',
        'source_report_date',
        'source_status',
        'source_terpasang_bh',
        'source_kurang_bh',
        'latitude',
        'longitude',
        'target_quantity',
        'installed_quantity',
        'remaining_quantity',
    ];

    protected function casts(): array
    {
        return [
            'source_report_date' => 'date',
            'source_terpasang_bh' => 'integer',
            'source_kurang_bh' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'target_quantity' => 'integer',
            'installed_quantity' => 'integer',
            'remaining_quantity' => 'integer',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function apkuk(): BelongsTo
    {
        return $this->belongsTo(Apkuk::class);
    }

    /** Origin of a POSKO APKUK WO created from an approved INSPEKSI finding. */
    public function inspectionFinding(): BelongsTo
    {
        return $this->belongsTo(InspectionFinding::class, 'inspection_finding_id');
    }
}
