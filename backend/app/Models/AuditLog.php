<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Jejak audit umum (master data, akun, keputusan Admin di luar WO). Append-only.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'CREATED';

    public const ACTION_UPDATED = 'UPDATED';

    public const ACTION_DELETED = 'DELETED';

    public const ACTION_ACTIVATED = 'ACTIVATED';

    public const ACTION_DEACTIVATED = 'DEACTIVATED';

    public const ACTION_PASSWORD_RESET = 'PASSWORD_RESET';

    public const ACTION_APPROVED = 'APPROVED';

    public const ACTION_REJECTED = 'REJECTED';

    public const ACTION_RESOLVED = 'RESOLVED';

    public const ACTION_LABELS = [
        self::ACTION_CREATED => 'Dibuat',
        self::ACTION_UPDATED => 'Diubah',
        self::ACTION_DELETED => 'Dihapus',
        self::ACTION_ACTIVATED => 'Diaktifkan',
        self::ACTION_DEACTIVATED => 'Dinonaktifkan',
        self::ACTION_PASSWORD_RESET => 'Kata sandi direset',
        self::ACTION_APPROVED => 'Disetujui',
        self::ACTION_REJECTED => 'Ditolak',
        self::ACTION_RESOLVED => 'Ditindaklanjuti',
    ];

    public const ENTITY_LABELS = [
        'user' => 'Akun pengguna',
        'field_team' => 'Tim lapangan',
        'field_personnel' => 'Petugas',
        'reference_code' => 'Kode referensi',
        'shift_assignment' => 'Jadwal shift tim',
        'inspection' => 'Inspeksi',
        'inspection_finding' => 'Temuan inspeksi',
        'work_escalation' => 'Limpahan',
        'work_order_issue' => 'Kendala WO',
    ];

    protected $fillable = [
        'actor_user_id',
        'actor_field_team_id',
        'entity_type',
        'entity_id',
        'entity_label',
        'action',
        'before',
        'after',
        'reason',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('Audit log tidak boleh diubah atau dihapus.');
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorFieldTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class, 'actor_field_team_id');
    }

    public function entityTypeLabel(): string
    {
        return self::ENTITY_LABELS[$this->entity_type] ?? $this->entity_type;
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
