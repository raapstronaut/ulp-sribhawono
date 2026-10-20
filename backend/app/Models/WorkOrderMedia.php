<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti foto WO. Setiap file disimpan sebagai baris baru; foto lama tidak ditimpa.
 */
class WorkOrderMedia extends Model
{
    use HasUuids;

    public const PHOTO_BEFORE = 'BEFORE';
    public const PHOTO_AFTER = 'AFTER';

    public const SOURCE_CAMERA = 'CAMERA';
    public const SOURCE_GALLERY = 'GALLERY';

    protected $table = 'work_order_media';

    protected $fillable = [
        'work_order_id',
        'work_order_report_id',
        'photo_type',
        'capture_source',
        'disk',
        'storage_path',
        'sha256',
        'mime_type',
        'size_bytes',
        'captured_at',
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
            'uploaded_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WorkOrderReport::class, 'work_order_report_id');
    }
}
