<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laporan pelaksanaan dari lapangan beserta realisasi jumlah APKUK.
 */
class WorkOrderReport extends Model
{
    use HasUuids;

    // Pemasangan selesai: langsung SELESAI tanpa ACC Admin.
    public const TYPE_PEMASANGAN_SELESAI = 'PEMASANGAN_SELESAI';
    // APKUK di tiang ternyata sudah lengkap: wajib verifikasi Admin.
    public const TYPE_TEMUAN_SUDAH_LENGKAP = 'TEMUAN_SUDAH_LENGKAP';

    public const VERIFICATION_TIDAK_PERLU = 'TIDAK_PERLU';
    public const VERIFICATION_MENUNGGU = 'MENUNGGU_VERIFIKASI';
    public const VERIFICATION_DISETUJUI = 'DISETUJUI';
    public const VERIFICATION_DITOLAK = 'DITOLAK';

    protected $fillable = [
        'work_order_id',
        'report_type',
        'verification_status',
        'installed_quantity',
        'remaining_quantity',
        'notes',
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'occurred_at',
        'received_at',
        'submitted_by_field_team_id',
        'submitted_by_user_id',
        'verified_by_user_id',
        'verified_at',
        'verification_note',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'installed_quantity' => 'integer',
            'remaining_quantity' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(WorkOrderReportPersonnel::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(WorkOrderMedia::class);
    }

    public function submittedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'submitted_by_field_team_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
