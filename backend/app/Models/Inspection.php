<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kunjungan INSPEKSI. "Tidak Ada Temuan" dicatat jujur (has_finding = false) dan
 * tidak dihitung sebagai temuan.
 */
class Inspection extends Model
{
    use HasUuids;

    public const SOURCE_MOBILE = 'MOBILE';

    public const SOURCE_ADMIN = 'ADMIN';

    // Legacy storage values only. There is ONE inspection form; these values are
    // retained for historical records and existing database migrations/clients.
    // Never use inspection_type to decide which approved finding may become a WO.
    public const TYPE_REGULAR = 'UMUM';

    public const TYPE_FULL = 'FULL';

    protected $fillable = [
        'code',
        'field_team_id',
        'penyulang_id',
        'section_name',
        'visited_at',
        'has_finding',
        'summary',
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'source',
        'inspection_type',
        'submitted_by_field_team_id',
        'submitted_by_user_id',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'has_finding' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
        ];
    }

    public function fieldTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class);
    }

    public function penyulang(): BelongsTo
    {
        return $this->belongsTo(Penyulang::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(InspectionFinding::class)->orderBy('created_at')->orderBy('id');
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(InspectionPersonnel::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function submittedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'submitted_by_field_team_id');
    }
}
