<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkOrder extends Model
{
    use HasUuids;

    public const TEAM_POSKO = 'POSKO';
    public const TEAM_HARSUS = 'HARSUS';
    public const TEAM_ROW = 'ROW';

    public const TYPE_PEMASANGAN_APKUK = 'PEMASANGAN_APKUK';

    public const SOURCE_ADMIN_MANUAL = 'ADMIN_MANUAL';
    public const SOURCE_SYSTEM = 'SYSTEM';
    public const SOURCE_ESCALATION = 'ESCALATION';

    protected $fillable = [
        'code',
        'team_type',
        'work_type',
        'source',
        'status',
        'posko_id',
        'field_team_id',
        'penyulang_id',
        'section_name',
        'work_date',
        'shift_code',
        'scheduled_start_at',
        'due_at',
        'parent_work_order_id',
        'notes',
        'created_by_user_id',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'work_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function posko(): BelongsTo
    {
        return $this->belongsTo(Posko::class);
    }

    public function fieldTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class);
    }

    public function penyulang(): BelongsTo
    {
        return $this->belongsTo(Penyulang::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_work_order_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_work_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function apkukTarget(): HasOne
    {
        return $this->hasOne(WorkOrderApkukTarget::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(WorkOrderStatusLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(WorkOrderReschedule::class)->latest();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(WorkOrderReport::class)->latest();
    }

    public function media(): HasMany
    {
        return $this->hasMany(WorkOrderMedia::class);
    }

    public function scopeApkuk(Builder $query): Builder
    {
        return $query->where('team_type', self::TEAM_POSKO)
            ->where('work_type', self::TYPE_PEMASANGAN_APKUK);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn (WorkOrderStatus $s) => $s->value, WorkOrderStatus::active()));
    }

    public function scopeOverdue(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->active()->whereNotNull('due_at')->where('due_at', '<', $now ?? now());
    }

    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->status->isActive()
            && $this->due_at !== null
            && $this->due_at->lt($now ?? now());
    }
}
