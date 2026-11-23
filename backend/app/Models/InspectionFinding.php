<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu temuan inspeksi dengan lokasi dan foto sendiri. Wajib divalidasi Admin
 * sebelum menjadi dasar tindakan (WO tindak lanjut).
 */
class InspectionFinding extends Model
{
    use HasUuids;

    public const VALIDATION_MENUNGGU = 'MENUNGGU_VALIDASI';

    public const VALIDATION_DISETUJUI = 'DISETUJUI';

    public const VALIDATION_DITOLAK = 'DITOLAK';

    public const VALIDATION_LABELS = [
        self::VALIDATION_MENUNGGU => 'Menunggu validasi',
        self::VALIDATION_DISETUJUI => 'Disetujui',
        self::VALIDATION_DITOLAK => 'Ditolak',
    ];

    protected $fillable = [
        'inspection_id',
        'finding_code',
        'description',
        'keypoint_id',
        'asset_label',
        'latitude',
        'longitude',
        'occurred_at',
        'suggested_team_type',
        'apkuk_no_tiang',
        'apkuk_target_quantity',
        'validation_status',
        'validated_by_user_id',
        'validated_at',
        'validation_note',
        'follow_up_work_order_id',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'occurred_at' => 'datetime',
            'validated_at' => 'datetime',
            'apkuk_target_quantity' => 'integer',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function keypoint(): BelongsTo
    {
        return $this->belongsTo(Keypoint::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(InspectionFindingMedia::class);
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }

    public function followUpWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'follow_up_work_order_id');
    }

    /**
     * Approved POSKO APKUK finding from the single INSPEKSI form.
     * Eligibility still requires target deduplication, POSKO mapping and scheduling checks.
     */
    public function isApprovedPoskoApkukTarget(): bool
    {
        return $this->validation_status === self::VALIDATION_DISETUJUI
            && $this->suggested_team_type === WorkOrder::TEAM_POSKO
            && $this->inspection !== null
            && ! in_array(mb_strtoupper(trim((string) $this->apkuk_no_tiang)), ['', '-', 'DUMMY'], true)
            && (int) $this->apkuk_target_quantity > 0;
    }

    /** @deprecated Old method name retained for code outside this batch. */
    public function isApprovedFullApkukTarget(): bool
    {
        return $this->isApprovedPoskoApkukTarget();
    }

    public function validationLabel(): string
    {
        return self::VALIDATION_LABELS[$this->validation_status] ?? $this->validation_status;
    }
}
