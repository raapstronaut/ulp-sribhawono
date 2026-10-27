<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\FieldTeamShiftAssignment;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRUD master milik aplikasi: tim lapangan, petugas, kode referensi, dan jadwal shift tim.
 * Posko dan Penyulang adalah master resmi hasil `topology:sync-core-masters` sehingga
 * tidak diubah dari sini. Data yang masih direferensikan tidak dihapus; nonaktifkan saja.
 */
class MasterDataService
{
    public const TEAM_ROLES = ['POSKO', 'HARSUS', 'INSPEKSI', 'ROW'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, role: string, posko_id?: ?string, is_active?: bool}  $data
     */
    public function saveTeam(User $admin, ?FieldTeam $team, array $data, ?string $reason = null): FieldTeam
    {
        if (! in_array($data['role'], self::TEAM_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Role tim tidak dikenal.']);
        }
        $poskoId = filled($data['posko_id'] ?? null) ? $data['posko_id'] : null;
        if ($data['role'] === 'POSKO' && $poskoId === null) {
            throw ValidationException::withMessages(['posko_id' => 'Tim POSKO wajib terhubung ke satu Posko.']);
        }
        if ($poskoId !== null && ! Posko::query()->whereKey($poskoId)->exists()) {
            throw ValidationException::withMessages(['posko_id' => 'Posko tidak ditemukan.']);
        }

        return DB::transaction(function () use ($admin, $team, $data, $poskoId, $reason): FieldTeam {
            if ($team !== null && $team->role !== $data['role'] && $this->teamInUse($team)) {
                throw ValidationException::withMessages(['role' => 'Role tim tidak dapat diubah karena tim sudah memiliki akun, petugas, atau pekerjaan.']);
            }

            $attributes = [
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'role' => $data['role'],
                'posko_id' => $poskoId,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            return $this->persist($admin, $team ?? new FieldTeam, $attributes, 'field_team', fn (FieldTeam $t) => $t->code.' · '.$t->name, $reason);
        });
    }

    public function deleteTeam(User $admin, FieldTeam $team, string $reason): void
    {
        if ($this->teamInUse($team)) {
            throw ValidationException::withMessages(['delete' => 'Tim masih direferensikan (akun, petugas, WO, inspeksi, atau jadwal shift). Nonaktifkan tim sebagai gantinya.']);
        }
        $this->remove($admin, $team, 'field_team', $team->code.' · '.$team->name, $reason);
    }

    /**
     * @param  array{field_team_id: string, personnel_code?: ?string, name: string, is_active?: bool}  $data
     */
    public function savePersonnel(User $admin, ?FieldPersonnel $person, array $data, ?string $reason = null): FieldPersonnel
    {
        $team = FieldTeam::query()->find($data['field_team_id'])
            ?? throw ValidationException::withMessages(['field_team_id' => 'Tim tidak ditemukan.']);
        if ($person !== null && $person->field_team_id !== $team->getKey() && $this->personnelInUse($person)) {
            throw ValidationException::withMessages(['field_team_id' => 'Petugas yang sudah tercatat di laporan tidak dapat dipindah tim; nonaktifkan dan buat data baru di tim tujuan.']);
        }

        return DB::transaction(fn () => $this->persist($admin, $person ?? new FieldPersonnel, [
            'field_team_id' => $team->getKey(),
            'personnel_code' => filled($data['personnel_code'] ?? null) ? mb_strtoupper(trim($data['personnel_code'])) : null,
            'name' => trim($data['name']),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ], 'field_personnel', fn (FieldPersonnel $p) => $p->name, $reason));
    }

    public function deletePersonnel(User $admin, FieldPersonnel $person, string $reason): void
    {
        if ($this->personnelInUse($person)) {
            throw ValidationException::withMessages(['delete' => 'Petugas sudah tercatat pada laporan pekerjaan atau inspeksi. Nonaktifkan petugas sebagai gantinya.']);
        }
        $this->remove($admin, $person, 'field_personnel', $person->name, $reason);
    }

    /**
     * @param  array{category: string, code: string, label: string, role_scope?: ?string, is_active?: bool, sort_order?: int|string|null}  $data
     */
    public function saveReferenceCode(User $admin, ?ReferenceCode $code, array $data, ?string $reason = null): ReferenceCode
    {
        $roleScope = filled($data['role_scope'] ?? null) ? $data['role_scope'] : null;
        if ($roleScope !== null && ! in_array($roleScope, ReferenceCode::ROLE_SCOPES, true)) {
            throw ValidationException::withMessages(['role_scope' => 'Cakupan role tidak dikenal.']);
        }
        $category = mb_strtoupper(trim($data['category']));
        $value = mb_strtoupper(trim($data['code']));
        if ($code !== null && ($code->category !== $category || $code->code !== $value) && $this->codeInUse($code)) {
            throw ValidationException::withMessages(['code' => 'Kode yang sudah dipakai transaksi tidak dapat diganti; nonaktifkan dan buat kode baru.']);
        }

        return DB::transaction(fn () => $this->persist($admin, $code ?? new ReferenceCode, [
            'category' => $category,
            'code' => $value,
            'label' => trim($data['label']),
            'role_scope' => $roleScope,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ], 'reference_code', fn (ReferenceCode $c) => $c->category.' · '.$c->code, $reason));
    }

    public function deleteReferenceCode(User $admin, ReferenceCode $code, string $reason): void
    {
        if ($this->codeInUse($code)) {
            throw ValidationException::withMessages(['delete' => 'Kode sudah dipakai transaksi (reschedule, limpahan, atau temuan). Nonaktifkan kode sebagai gantinya.']);
        }
        $this->remove($admin, $code, 'reference_code', $code->category.' · '.$code->code, $reason);
    }

    /**
     * @param  array{field_team_id: string, shift_code: string, effective_from: string, effective_until?: ?string}  $data
     */
    public function saveShiftAssignment(User $admin, ?FieldTeamShiftAssignment $assignment, array $data, ?string $reason = null): FieldTeamShiftAssignment
    {
        $team = FieldTeam::query()->find($data['field_team_id']);
        if ($team === null || $team->role !== 'POSKO') {
            throw ValidationException::withMessages(['field_team_id' => 'Jadwal shift hanya untuk tim POSKO.']);
        }
        $until = filled($data['effective_until'] ?? null) ? $data['effective_until'] : null;
        if ($until !== null && $until < $data['effective_from']) {
            throw ValidationException::withMessages(['effective_until' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);
        }

        return DB::transaction(function () use ($admin, $assignment, $data, $team, $until, $reason): FieldTeamShiftAssignment {
            // Satu tim hanya boleh punya satu shift pada tanggal yang sama.
            $overlap = FieldTeamShiftAssignment::query()
                ->where('field_team_id', $team->getKey())
                ->when($assignment !== null, fn ($q) => $q->whereKeyNot($assignment->getKey()))
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $data['effective_from']))
                ->when($until !== null, fn ($q) => $q->where('effective_from', '<=', $until))
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => 'Periode bertumpang tindih dengan jadwal shift tim yang sudah ada.']);
            }

            return $this->persist($admin, $assignment ?? new FieldTeamShiftAssignment, [
                'field_team_id' => $team->getKey(),
                'shift_code' => $data['shift_code'],
                'effective_from' => $data['effective_from'],
                'effective_until' => $until,
            ], 'shift_assignment', fn (FieldTeamShiftAssignment $a) => $team->code.' · Shift '.$a->shift_code, $reason);
        });
    }

    public function deleteShiftAssignment(User $admin, FieldTeamShiftAssignment $assignment, string $reason): void
    {
        $assignment->loadMissing('team');
        $this->remove($admin, $assignment, 'shift_assignment', ($assignment->team?->code ?? '-').' · Shift '.$assignment->shift_code, $reason);
    }

    public function teamInUse(FieldTeam $team): bool
    {
        $id = $team->getKey();

        return $team->personnel()->exists()
            || User::query()->where('field_team_id', $id)->exists()
            || DB::table('work_orders')->where('field_team_id', $id)->exists()
            || DB::table('inspections')->where('field_team_id', $id)->orWhere('submitted_by_field_team_id', $id)->exists()
            || DB::table('field_team_shift_assignments')->where('field_team_id', $id)->exists()
            || DB::table('work_order_reports')->where('submitted_by_field_team_id', $id)->exists()
            || DB::table('work_order_status_logs')->where('actor_field_team_id', $id)->exists();
    }

    public function personnelInUse(FieldPersonnel $person): bool
    {
        return DB::table('work_order_report_personnel')->where('field_personnel_id', $person->getKey())->exists()
            || DB::table('inspection_personnel')->where('field_personnel_id', $person->getKey())->exists();
    }

    public function codeInUse(ReferenceCode $code): bool
    {
        return match ($code->category) {
            ReferenceCode::CATEGORY_RESCHEDULE_REASON => DB::table('work_order_reschedules')->where('reason_code', $code->code)->exists(),
            ReferenceCode::CATEGORY_ESCALATION_REASON => DB::table('work_escalations')->where('reason_code', $code->code)->exists(),
            ReferenceCode::CATEGORY_INSPECTION_FINDING => DB::table('inspection_findings')->where('finding_code', $code->code)->exists(),
            default => false,
        };
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @param  array<string, mixed>  $attributes
     * @param  callable(T): string  $label
     * @return T
     */
    private function persist(User $admin, Model $model, array $attributes, string $entity, callable $label, ?string $reason): Model
    {
        $creating = ! $model->exists;
        // Nilai ter-cast (bool, tanggal) agar audit konsisten antar-driver database.
        $original = $creating ? [] : $model->getOriginal();
        $model->fill($attributes);
        if (! $creating && ! $model->isDirty()) {
            return $model;
        }
        $activeChanged = ! $creating && $model->isDirty('is_active');
        $model->save();

        if ($creating) {
            $this->audit->record($entity, $model->getKey(), $label($model), AuditLog::ACTION_CREATED, null, $attributes, $admin, $reason);
        } else {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            $action = $activeChanged && count($changes) === 1
                ? ($model->getAttribute('is_active') ? AuditLog::ACTION_ACTIVATED : AuditLog::ACTION_DEACTIVATED)
                : AuditLog::ACTION_UPDATED;
            $this->audit->record($entity, $model->getKey(), $label($model), $action, array_intersect_key($original, $changes), $changes, $admin, $reason);
        }

        return $model;
    }

    private function remove(User $admin, Model $model, string $entity, string $label, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Alasan penghapusan wajib diisi (minimal 5 karakter).']);
        }

        DB::transaction(function () use ($admin, $model, $entity, $label, $reason): void {
            $before = $model->getOriginal();
            $id = $model->getKey();
            $model->delete();
            $this->audit->record($entity, $id, $label, AuditLog::ACTION_DELETED, $before, null, $admin, $reason);
        });
    }
}
