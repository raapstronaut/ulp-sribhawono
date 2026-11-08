<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Jejak audit WO yang hanya boleh ditambah (append-only).
 */
class WorkOrderStatusLog extends Model
{
    public const ACTION_CREATED = 'CREATED';

    public const ACTION_STATUS_CHANGED = 'STATUS_CHANGED';

    public const ACTION_CANCELLED = 'CANCELLED';

    public const ACTION_UPDATED = 'UPDATED';

    public const ACTION_STARTED = 'STARTED';

    public const ACTION_REPORT_SUBMITTED = 'REPORT_SUBMITTED';

    public const ACTION_REPORT_APPROVED = 'REPORT_APPROVED';

    public const ACTION_REPORT_REJECTED = 'REPORT_REJECTED';

    public const ACTION_RESCHEDULE_REQUESTED = 'RESCHEDULE_REQUESTED';

    public const ACTION_RESCHEDULE_APPROVED = 'RESCHEDULE_APPROVED';

    public const ACTION_RESCHEDULE_REJECTED = 'RESCHEDULE_REJECTED';

    public const ACTION_ESCALATION_REQUESTED = 'ESCALATION_REQUESTED';

    public const ACTION_ESCALATION_APPROVED = 'ESCALATION_APPROVED';

    public const ACTION_ESCALATION_REJECTED = 'ESCALATION_REJECTED';

    public const ACTION_ISSUE_REPORTED = 'ISSUE_REPORTED';

    public const ACTION_ISSUE_RESOLVED = 'ISSUE_RESOLVED';

    public const ACTION_LABELS = [
        self::ACTION_CREATED => 'Dibuat',
        self::ACTION_STATUS_CHANGED => 'Status berubah',
        self::ACTION_CANCELLED => 'Dibatalkan',
        self::ACTION_UPDATED => 'Data/jadwal diubah',
        self::ACTION_STARTED => 'Mulai dikerjakan',
        self::ACTION_REPORT_SUBMITTED => 'Laporan dikirim',
        self::ACTION_REPORT_APPROVED => 'Laporan disetujui',
        self::ACTION_REPORT_REJECTED => 'Laporan ditolak',
        self::ACTION_RESCHEDULE_REQUESTED => 'Reschedule diajukan',
        self::ACTION_RESCHEDULE_APPROVED => 'Reschedule disetujui',
        self::ACTION_RESCHEDULE_REJECTED => 'Reschedule ditolak',
        self::ACTION_ESCALATION_REQUESTED => 'Limpahan diajukan',
        self::ACTION_ESCALATION_APPROVED => 'Limpahan disetujui',
        self::ACTION_ESCALATION_REJECTED => 'Limpahan ditolak',
        self::ACTION_ISSUE_REPORTED => 'Kendala dilaporkan',
        self::ACTION_ISSUE_RESOLVED => 'Kendala ditindaklanjuti',
    ];

    public const UPDATED_AT = null;

    protected $fillable = [
        'work_order_id',
        'action',
        'from_status',
        'to_status',
        'actor_user_id',
        'actor_field_team_id',
        'reason',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('Log audit WO tidak boleh diubah atau dihapus.');
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorFieldTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'actor_field_team_id');
    }
}
