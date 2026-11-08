<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laporan nihil PASILA: pernyataan eksplisit petugas bahwa patroli dilakukan dan tidak ada temuan.
 * Bukan temuan dan tidak pernah dihitung sebagai temuan. Satu per sesi. Bila temuan masuk kemudian
 * (mis. kiriman offline yang terlambat), laporan ini DIGANTIKAN_TEMUAN dan tetap tersimpan.
 */
class PasilaNilReport extends Model
{
    use HasUuids;

    public const STATUS_AKTIF = 'AKTIF';

    public const STATUS_DIGANTIKAN = 'DIGANTIKAN_TEMUAN';

    public const STATUS_LABELS = [
        self::STATUS_AKTIF => 'Nihil (aktif)',
        self::STATUS_DIGANTIKAN => 'Digantikan temuan',
    ];

    protected $fillable = [
        'pasila_session_id',
        'note',
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'occurred_at',
        'received_at',
        'status',
        'superseded_by_finding_id',
        'superseded_at',
        'source',
        'submitted_by_field_team_id',
        'submitted_by_user_id',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PasilaSession::class, 'pasila_session_id');
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(PasilaNilReportPersonnel::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(PasilaFinding::class, 'superseded_by_finding_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
