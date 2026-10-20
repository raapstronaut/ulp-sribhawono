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
