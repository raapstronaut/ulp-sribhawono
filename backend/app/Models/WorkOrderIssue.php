<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Special case pelaksanaan WO: pekerjaan tidak dapat dilaksanakan, lokasi/data tidak
 * sesuai, atau kendala lapangan. Dicatat dan dipantau sampai ditindaklanjuti Admin.
 */
class WorkOrderIssue extends Model
{
    use HasUuids;

    public const TYPE_TIDAK_DAPAT_DILAKSANAKAN = 'TIDAK_DAPAT_DILAKSANAKAN';

    public const TYPE_LOKASI_TIDAK_SESUAI = 'LOKASI_TIDAK_SESUAI';

    public const TYPE_DATA_TIDAK_SESUAI = 'DATA_TIDAK_SESUAI';

    public const TYPE_KENDALA_LAPANGAN = 'KENDALA_LAPANGAN';

    public const TYPE_LABELS = [
        self::TYPE_TIDAK_DAPAT_DILAKSANAKAN => 'Pekerjaan tidak dapat dilaksanakan',
        self::TYPE_LOKASI_TIDAK_SESUAI => 'Lokasi tidak sesuai',
        self::TYPE_DATA_TIDAK_SESUAI => 'Data tidak sesuai',
        self::TYPE_KENDALA_LAPANGAN => 'Kendala lapangan',
    ];

    public const STATUS_TERBUKA = 'TERBUKA';

    public const STATUS_SELESAI = 'SELESAI';

    public const STATUS_LABELS = [
        self::STATUS_TERBUKA => 'Terbuka',
        self::STATUS_SELESAI => 'Ditindaklanjuti',
    ];

    // Tindak lanjut yang dicatat; tindakan nyatanya (ubah jadwal, limpahan, batal) tetap lewat alurnya sendiri.
    public const RESOLUTION_LANJUT = 'LANJUT';

    public const RESOLUTION_JADWAL_DIUBAH = 'JADWAL_DIUBAH';

    public const RESOLUTION_DILIMPAHKAN = 'DILIMPAHKAN';

    public const RESOLUTION_DIBATALKAN = 'DIBATALKAN';

    public const RESOLUTION_DATA_DIKOREKSI = 'DATA_DIKOREKSI';

    public const RESOLUTION_LABELS = [
        self::RESOLUTION_LANJUT => 'Pekerjaan dilanjutkan',
        self::RESOLUTION_JADWAL_DIUBAH => 'Jadwal diubah',
        self::RESOLUTION_DILIMPAHKAN => 'Dilimpahkan ke tim lain',
        self::RESOLUTION_DIBATALKAN => 'WO dibatalkan',
        self::RESOLUTION_DATA_DIKOREKSI => 'Data sumber dikoreksi',
    ];

    protected $fillable = [
        'work_order_id',
        'issue_type',
        'description',
        'status',
        'reported_by_field_team_id',
        'reported_by_user_id',
        'resolution_action',
        'resolution_note',
        'resolved_by_user_id',
        'resolved_at',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function reportedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'reported_by_field_team_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->issue_type] ?? $this->issue_type;
    }
}
