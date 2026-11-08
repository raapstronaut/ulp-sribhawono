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

    public const TYPE_PEKERJAAN_KHUSUS = 'PEKERJAAN_KHUSUS';

    // P0 POSKO (B3.3): dibuat hanya lewat P0Service dari sesi PASILA; tidak termasuk kuota APKUK.
    public const TYPE_P0 = 'P0';

    public const TYPE_RABAS = 'RABAS';

    public const TYPE_PANGKAS = 'PANGKAS';

    public const TYPE_TEBANG = 'TEBANG';

    public const ROW_WORK_TYPES = [self::TYPE_RABAS, self::TYPE_PANGKAS, self::TYPE_TEBANG];

    public const SOURCE_ADMIN_MANUAL = 'ADMIN_MANUAL';

    public const SOURCE_SYSTEM = 'SYSTEM';

    public const SOURCE_ESCALATION = 'ESCALATION';

    public const TEAM_LABELS = [
        self::TEAM_POSKO => 'POSKO',
        self::TEAM_HARSUS => 'HARSUS',
        self::TEAM_ROW => 'ROW',
    ];

    // Jenis pekerjaan yang sah per tim (PRD §6).
    public const WORK_TYPES_BY_TEAM = [
        self::TEAM_POSKO => [self::TYPE_PEMASANGAN_APKUK, self::TYPE_P0],
        self::TEAM_HARSUS => [self::TYPE_PEKERJAAN_KHUSUS],
        self::TEAM_ROW => self::ROW_WORK_TYPES,
    ];

    public const WORK_TYPE_LABELS = [
        self::TYPE_PEMASANGAN_APKUK => 'Pemasangan APKUK',
        self::TYPE_P0 => 'P0 (pekerjaan prioritas POSKO)',
        self::TYPE_PEKERJAAN_KHUSUS => 'Pekerjaan khusus',
        self::TYPE_RABAS => 'Rabas',
        self::TYPE_PANGKAS => 'Pangkas',
        self::TYPE_TEBANG => 'Tebang',
    ];

    public const SOURCE_LABELS = [
        self::SOURCE_ADMIN_MANUAL => 'Manual Admin',
        self::SOURCE_SYSTEM => 'Otomatis sistem',
        self::SOURCE_ESCALATION => 'Limpahan (eskalasi)',
    ];

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
        'generation_run_id',
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

    /** Eksekusi generator yang membuat WO ini (null untuk WO manual/limpahan). */
    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(ApkukGenerationRun::class, 'generation_run_id');
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

    public function locationTarget(): HasOne
    {
        return $this->hasOne(WorkOrderLocationTarget::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(WorkEscalation::class, 'source_work_order_id')->latest();
    }

    public function sourceEscalation(): HasOne
    {
        return $this->hasOne(WorkEscalation::class, 'child_work_order_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(WorkOrderIssue::class)->latest();
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

    /**
     * Pembatalan Admin: WO APKUK POSKO, serta WO ROW/HARSUS yang dibuat manual oleh Admin
     * dan tidak terlibat limpahan. Aturan batal untuk child eskalasi dan WO otomatis ROW
     * belum diputuskan, sehingga keduanya tetap tidak dapat dibatalkan dari Web Admin.
     */
    public function supportsAdminCancellation(): bool
    {
        // Kebijakan pembatalan P0 belum disahkan: semua WO P0 (manual maupun dari usulan SYSTEM) tidak dapat dibatalkan.
        if ($this->isP0()) {
            return false;
        }
        if ($this->team_type === self::TEAM_POSKO && $this->work_type === self::TYPE_PEMASANGAN_APKUK) {
            return true;
        }

        return $this->source === self::SOURCE_ADMIN_MANUAL
            && $this->parent_work_order_id === null
            && ! $this->children()->exists();
    }

    public function teamLabel(): string
    {
        return self::TEAM_LABELS[$this->team_type] ?? $this->team_type;
    }

    public function workTypeLabel(): string
    {
        return self::WORK_TYPE_LABELS[$this->work_type] ?? $this->work_type;
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }

    public function isApkuk(): bool
    {
        return $this->team_type === self::TEAM_POSKO && $this->work_type === self::TYPE_PEMASANGAN_APKUK;
    }

    public function isP0(): bool
    {
        return $this->team_type === self::TEAM_POSKO && $this->work_type === self::TYPE_P0;
    }

    public function p0Item(): HasOne
    {
        return $this->hasOne(P0Item::class);
    }

    /**
     * Koordinat target untuk Peta/Google Maps: target APKUK atau target lokasi.
     *
     * @return array{0: float, 1: float}|null
     */
    public function targetCoordinates(): ?array
    {
        $target = $this->isApkuk() ? $this->apkukTarget : $this->locationTarget;
        if ($target === null || $target->latitude === null || $target->longitude === null) {
            return null;
        }

        return [(float) $target->latitude, (float) $target->longitude];
    }

    public function targetLabel(): ?string
    {
        if ($this->isApkuk()) {
            return $this->apkukTarget?->no_tiang;
        }

        return $this->locationTarget?->label;
    }

    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->status->isActive()
            && $this->due_at !== null
            && $this->due_at->lt($now ?? now());
    }
}
