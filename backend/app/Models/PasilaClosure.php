<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penutupan sesi PASILA oleh tim POSKO: hasil patroli saat ditutup (ADA_TEMUAN/NIHIL), pelaku, waktu,
 * dan snapshot P0 serta WO APKUK pada saat itu. Record tidak diubah oleh kiriman yang terlambat;
 * ketidaksesuaian dengan data sesudahnya dihitung dan ditampilkan (lihat PasilaSession).
 */
class PasilaClosure extends Model
{
    use HasUuids;

    public const OUTCOME_ADA_TEMUAN = 'ADA_TEMUAN';

    public const OUTCOME_NIHIL = 'NIHIL';

    public const OUTCOME_LABELS = [
        self::OUTCOME_ADA_TEMUAN => 'Ditutup dengan temuan',
        self::OUTCOME_NIHIL => 'Ditutup nihil',
    ];

    protected $fillable = [
        'pasila_session_id',
        'outcome',
        'finding_count',
        'pasila_nil_report_id',
        'note',
        'latitude',
        'longitude',
        'gps_accuracy_m',
        'personnel',
        'snapshot',
        'occurred_at',
        'received_at',
        'submitted_by_field_team_id',
        'submitted_by_user_id',
        'client_request_id',
    ];

    protected function casts(): array
    {
        return [
            'finding_count' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2',
            'personnel' => 'array',
            'snapshot' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PasilaSession::class, 'pasila_session_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function submittedByTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'submitted_by_field_team_id');
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOME_LABELS[$this->outcome] ?? $this->outcome;
    }
}
