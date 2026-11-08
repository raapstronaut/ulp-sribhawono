<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan limpahan pekerjaan lintas tim. WO sumber tidak dihapus atau dipindah;
 * ACC Admin membuat child WO dan menandai sumber SELESAI_DITERUSKAN.
 */
class WorkEscalation extends Model
{
    use HasUuids;

    public const STATUS_MENUNGGU_ACC = 'MENUNGGU_ACC';

    public const STATUS_DISETUJUI = 'DISETUJUI';

    public const STATUS_DITOLAK = 'DITOLAK';

    public const STATUS_LABELS = [
        self::STATUS_MENUNGGU_ACC => 'Menunggu ACC',
        self::STATUS_DISETUJUI => 'Disetujui',
        self::STATUS_DITOLAK => 'Ditolak',
    ];

    protected $fillable = [
        'source_work_order_id',
        'target_team_type',
        'target_work_type',
        'reason_code',
        'note',
        'requested_by_field_team_id',
        'requested_by_user_id',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
        'child_work_order_id',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function sourceWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'source_work_order_id');
    }

    public function childWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'child_work_order_id');
    }

    public function requestedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'requested_by_field_team_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
