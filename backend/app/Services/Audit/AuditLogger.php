<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Menulis jejak audit umum: pelaku, waktu, tindakan, objek, nilai sebelum/sesudah, alasan.
 * Nilai rahasia (kata sandi, token) tidak pernah dicatat.
 */
class AuditLogger
{
    private const SECRET_KEYS = ['password', 'remember_token', 'token'];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $entityType,
        string|int $entityId,
        ?string $entityLabel,
        string $action,
        ?array $before,
        ?array $after,
        ?User $actor,
        ?string $reason = null,
        ?FieldTeam $actorTeam = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor?->getKey(),
            'actor_field_team_id' => $actorTeam?->getKey(),
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'entity_label' => $entityLabel === null ? null : mb_substr($entityLabel, 0, 255),
            'action' => $action,
            'before' => $before === null ? null : $this->clean($before),
            'after' => $after === null ? null : $this->clean($after),
            'reason' => $reason,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * Mencatat perubahan model: hanya kolom yang benar-benar berubah.
     *
     * @param  array<string, mixed>  $original  atribut sebelum diubah ($model->getOriginal())
     */
    public function recordChanges(string $entityType, Model $model, array $original, ?string $label, ?User $actor, ?string $reason = null): ?AuditLog
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if ($changes === []) {
            return null;
        }

        $before = array_intersect_key($original, $changes);

        return $this->record($entityType, $model->getKey(), $label, AuditLog::ACTION_UPDATED, $before, $changes, $actor, $reason);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        foreach (self::SECRET_KEYS as $key) {
            unset($values[$key]);
        }

        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $values);
    }
}
