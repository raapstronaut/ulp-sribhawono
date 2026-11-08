<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi Section internal untuk satu laporan APKUK (lihat ApkukSectionService).
 * Nilai sumber tetap di apkuks.section; riwayat perubahan ada di audit_logs.
 */
class ApkukSectionOverride extends Model
{
    public const STATUS_ACTIVE = 'AKTIF';

    public const STATUS_REVOKED = 'DICABUT';

    public const BASIS_SECTION_PIC = 'SECTION_PIC_RAW';

    public const BASIS_SAME_PENYULANG = 'APKUK_PENYULANG_SAMA';

    public const BASIS_LABELS = [
        self::BASIS_SECTION_PIC => 'Pemutus pada SECTION_PIC_RAW',
        self::BASIS_SAME_PENYULANG => 'Section laporan APKUK lain pada penyulang yang sama',
    ];

    protected $fillable = [
        'apkuk_id',
        'apkuk_source_id',
        'section_name',
        'source_section_at_change',
        'reference_basis',
        'status',
        'reason',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    public function apkuk(): BelongsTo
    {
        return $this->belongsTo(Apkuk::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function basisLabel(): ?string
    {
        return $this->reference_basis === null ? null : (self::BASIS_LABELS[$this->reference_basis] ?? $this->reference_basis);
    }
}
