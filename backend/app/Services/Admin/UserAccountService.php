<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan akun: ADMIN (Web Admin) dan akun tim lapangan (Android/API).
 *
 * Pengaman: akun lapangan wajib terikat tim aktif dengan role yang sama; Admin tidak
 * dapat menonaktifkan atau menurunkan role akunnya sendiri; minimal satu ADMIN aktif
 * harus tersisa. Menonaktifkan akun atau mereset kata sandi mencabut token API dan sesi.
 */
class UserAccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, username: string, email: string, role: string, field_team_id?: ?string, password: string, is_active?: bool}  $data
     */
    public function create(User $admin, array $data): User
    {
        $teamId = $this->teamFor($data['role'], $data['field_team_id'] ?? null);

        return DB::transaction(function () use ($admin, $data, $teamId): User {
            $user = new User([
                'name' => trim($data['name']),
                'username' => trim($data['username']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
            ]);
            $user->forceFill([
                'role' => $data['role'],
                'field_team_id' => $teamId,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ])->save();

            $this->audit->record('user', $user->getKey(), $user->displayName(), AuditLog::ACTION_CREATED, null, $this->snapshot($user), $admin);

            return $user;
        });
    }

    /**
     * @param  array{name: string, username: string, email: string, role: string, field_team_id?: ?string}  $data
     */
    public function update(User $admin, User $user, array $data, ?string $reason = null): User
    {
        $teamId = $this->teamFor($data['role'], $data['field_team_id'] ?? null);

        return DB::transaction(function () use ($admin, $user, $data, $teamId, $reason): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ($user->is($admin) && $data['role'] !== User::ROLE_ADMIN) {
                throw ValidationException::withMessages(['role' => 'Anda tidak dapat menurunkan role akun Anda sendiri.']);
            }
            if ($user->role === User::ROLE_ADMIN && $data['role'] !== User::ROLE_ADMIN) {
                $this->assertAnotherActiveAdmin($user);
            }

            $before = $this->snapshot($user);
            $user->fill([
                'name' => trim($data['name']),
                'username' => trim($data['username']),
                'email' => mb_strtolower(trim($data['email'])),
            ])->forceFill([
                'role' => $data['role'],
                'field_team_id' => $teamId,
            ]);
            $roleChanged = $user->isDirty('role') || $user->isDirty('field_team_id');
            $user->save();

            if ($roleChanged) {
                // Hak akses berubah: token API lama tidak boleh tetap berlaku.
                $this->revokeAccess($user);
            }

            $after = $this->snapshot($user);
            $changed = array_keys(array_diff_assoc(array_map('strval', array_map(fn ($v) => $v ?? '', $after)), array_map('strval', array_map(fn ($v) => $v ?? '', $before))));
            if ($changed !== []) {
                $this->audit->record('user', $user->getKey(), $user->displayName(), AuditLog::ACTION_UPDATED,
                    array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $admin, $reason);
            }

            return $user;
        });
    }

    public function setActive(User $admin, User $user, bool $active, string $reason): User
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi (minimal 5 karakter).']);
        }

        return DB::transaction(function () use ($admin, $user, $active, $reason): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if (! $active && $user->is($admin)) {
                throw ValidationException::withMessages(['reason' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);
            }
            if (! $active && $user->role === User::ROLE_ADMIN) {
                $this->assertAnotherActiveAdmin($user);
            }
            if ($active && $user->isFieldAccount()) {
                $this->teamFor($user->role, $user->field_team_id);
            }
            if ($user->is_active === $active) {
                throw ValidationException::withMessages(['reason' => $active ? 'Akun sudah aktif.' : 'Akun sudah nonaktif.']);
            }

            $user->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $this->revokeAccess($user);
            }

            $this->audit->record('user', $user->getKey(), $user->displayName(),
                $active ? AuditLog::ACTION_ACTIVATED : AuditLog::ACTION_DEACTIVATED,
                ['is_active' => ! $active], ['is_active' => $active], $admin, $reason);

            return $user;
        });
    }

    public function resetPassword(User $admin, User $user, string $password, string $reason): User
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi (minimal 5 karakter).']);
        }

        return DB::transaction(function () use ($admin, $user, $password, $reason): User {
            $user->forceFill(['password' => $password])->save();
            if (! $user->is($admin)) {
                $this->revokeAccess($user);
            } else {
                $user->tokens()->delete();
            }

            // Kata sandi tidak pernah dicatat; hanya fakta bahwa direset.
            $this->audit->record('user', $user->getKey(), $user->displayName(), AuditLog::ACTION_PASSWORD_RESET, null, null, $admin, $reason);

            return $user;
        });
    }

    private function teamFor(string $role, ?string $teamId): ?string
    {
        if (! array_key_exists($role, User::ROLE_LABELS)) {
            throw ValidationException::withMessages(['role' => 'Role tidak dikenal.']);
        }
        if ($role === User::ROLE_ADMIN) {
            return null;
        }

        $team = filled($teamId) ? FieldTeam::query()->whereKey($teamId)->where('is_active', true)->first() : null;
        if ($team === null || $team->role !== $role) {
            throw ValidationException::withMessages(['field_team_id' => "Akun {$role} wajib terikat ke tim {$role} yang aktif."]);
        }

        return $team->getKey();
    }

    private function assertAnotherActiveAdmin(User $user): void
    {
        $others = User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->whereKeyNot($user->getKey())->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['role' => 'Minimal harus ada satu akun ADMIN aktif.']);
        }
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();
        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
        $user->forceFill(['remember_token' => null])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'field_team_id' => $user->field_team_id,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
