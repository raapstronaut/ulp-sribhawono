<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu temuan lapangan PASILA: satu titik dengan Section, kode temuan, uraian, GPS perangkat,
 * waktu kejadian, foto, dan petugas sendiri. Section disimpan sebagai catatan saat dilaporkan
 * (harus Section terverifikasi milik Posko sesi pada saat itu). P0 (B3.3) kelak merujuk id ini.
 */
class PasilaFinding extends Model
{
    use HasUuids;

    public const STATUS_TERCATAT = 'TERCATAT';

    public const SOURCE_MOBILE = 'MOBILE';

    public const COORDINATE_DEVICE = 'PERANGKAT';

    public const STATUS_LABELS = [
        self::STATUS_TERCATAT => 'Tercatat',
    ];

    protected $fillable = [
        'pasila_session_id',
        'sequence',
        'posko_id',
        'penyulang_id',
        'section_key',
        'section_name',
        'finding_code',
        'description',
        'keypoint_id',
        'asset_label',
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'coordinate_source',
        'occurred_at',
        'received_at',
        'status',
        'source',
        'submitted_by_field_team_id',
        'submitted_by_user_id',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PasilaSession::class, 'pasila_session_id');
    }

    public function posko(): BelongsTo
    {
        return $this->belongsTo(Posko::class);
    }

    public function penyulang(): BelongsTo
    {
        return $this->belongsTo(Penyulang::class);
    }

    public function keypoint(): BelongsTo
    {
        return $this->belongsTo(Keypoint::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PasilaFindingMedia::class);
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(PasilaFindingPersonnel::class);
    }

    public function submittedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'submitted_by_field_team_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
