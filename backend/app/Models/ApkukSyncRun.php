<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu eksekusi apkuk:sync. Dry-run tidak dicatat.
 */
class ApkukSyncRun extends Model
{
    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_SUCCESS_WITH_PENDING = 'SUCCESS_WITH_PENDING_RECONCILIATION';

    public const STATUS_FAILED = 'FAILED';

    public const STAGE_SOURCE_READ = 'SOURCE_READ';

    public const STAGE_SOURCE_VALIDATION = 'SOURCE_VALIDATION';

    public const STAGE_SNAPSHOT_GUARD = 'SNAPSHOT_GUARD';

    public const STAGE_DATABASE = 'DATABASE';

    public const STATUS_LABELS = [
        self::STATUS_RUNNING => 'Berjalan',
        self::STATUS_SUCCESS => 'Berhasil',
        self::STATUS_SUCCESS_WITH_PENDING => 'Berhasil, ada antrean rekonsiliasi',
        self::STATUS_FAILED => 'Gagal',
    ];

    public const STAGE_LABELS = [
        self::STAGE_SOURCE_READ => 'Pembacaan sumber',
        self::STAGE_SOURCE_VALIDATION => 'Validasi sumber',
        self::STAGE_SNAPSHOT_GUARD => 'Pengaman snapshot',
        self::STAGE_DATABASE => 'Database',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot_accepted' => 'boolean',
            'source_report_count' => 'integer',
            'counts' => 'array',
            'guard' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Snapshot valid terakhir: pembanding jumlah laporan untuk snapshot berikutnya.
     */
    public static function lastAccepted(): ?self
    {
        return static::query()->where('snapshot_accepted', true)->orderByDesc('id')->first();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
