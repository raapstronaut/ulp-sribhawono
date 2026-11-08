<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu eksekusi generator WO APKUK untuk satu shift: pratinjau (tanpa WO) atau generate.
 */
class ApkukGenerationRun extends Model
{
    public const MODE_PREVIEW = 'PREVIEW';

    public const MODE_GENERATE = 'GENERATE';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REFUSED = 'REFUSED';

    public const STATUS_FAILED = 'FAILED';

    public const TRIGGER_ADMIN_WEB = 'ADMIN_WEB';

    public const TRIGGER_CLI = 'CLI';

    public const TRIGGER_SCHEDULER = 'SCHEDULER';

    public const MODE_LABELS = [
        self::MODE_PREVIEW => 'Pratinjau (tanpa WO)',
        self::MODE_GENERATE => 'Generate WO',
    ];

    public const STATUS_LABELS = [
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_REFUSED => 'Ditolak pengaman',
        self::STATUS_FAILED => 'Gagal',
    ];

    protected $fillable = [
        'shift_instance_id',
        'mode',
        'status',
        'trigger',
        'strategy',
        'strategy_version',
        'is_machine_learning',
        'app_environment',
        'triggered_by_user_id',
        'summary',
        'held',
        'breakdown',
        'message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'is_machine_learning' => 'boolean',
            'summary' => 'array',
            'held' => 'array',
            'breakdown' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function shiftInstance(): BelongsTo
    {
        return $this->belongsTo(ShiftInstance::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function isPreview(): bool
    {
        return $this->mode === self::MODE_PREVIEW;
    }

    public function modeLabel(): string
    {
        return self::MODE_LABELS[$this->mode] ?? $this->mode;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
