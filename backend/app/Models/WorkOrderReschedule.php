<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan reschedule dari petugas: alasan dropdown + catatan wajib, lalu ACC Admin.
 */
class WorkOrderReschedule extends Model
{
    use HasUuids;

    public const STATUS_MENUNGGU_ACC = 'MENUNGGU_ACC';
    public const STATUS_DISETUJUI = 'DISETUJUI';
    public const STATUS_DITOLAK = 'DITOLAK';

    protected $fillable = [
        'work_order_id',
        'requested_by_field_team_id',
        'requested_by_user_id',
        'reason_code',
        'note',
        'from_work_date',
        'from_shift_code',
        'proposed_work_date',
        'proposed_shift_code',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'from_work_date' => 'date',
            'proposed_work_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function requestedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'requested_by_field_team_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
