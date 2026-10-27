<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot lokasi target WO HARSUS/ROW/limpahan. Data sumber dapat berubah setelah
 * sinkronisasi; target WO tetap seperti saat WO dibuat.
 */
class WorkOrderLocationTarget extends Model
{
    use HasUuids;

    public const TYPE_KEYPOINT = 'KEYPOINT';

    public const TYPE_VEGETASI = 'VEGETASI';

    public const TYPE_WORK_ORDER = 'WORK_ORDER';

    public const TYPE_INSPEKSI = 'INSPEKSI';

    public const TYPE_LABELS = [
        self::TYPE_KEYPOINT => 'Aset jaringan (keypoint)',
        self::TYPE_VEGETASI => 'Titik vegetasi (sumber staging)',
        self::TYPE_WORK_ORDER => 'Lokasi WO asal (limpahan)',
        self::TYPE_INSPEKSI => 'Lokasi temuan inspeksi',
    ];

    protected $fillable = [
        'work_order_id',
        'target_type',
        'source_ref',
        'target_key',
        'active_target_key',
        'label',
        'asset_type',
        'penyulang_source',
        'section_source',
        'latitude',
        'longitude',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'snapshot' => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->target_type] ?? $this->target_type;
    }
}
