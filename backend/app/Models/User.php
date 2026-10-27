<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// role, is_active, dan field_team_id sengaja tidak fillable: hanya diubah lewat UserAccountService.
#[Fillable(['name', 'email', 'username', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_ADMIN = 'ADMIN';

    public const ROLE_POSKO = 'POSKO';

    public const ROLE_HARSUS = 'HARSUS';

    public const ROLE_INSPEKSI = 'INSPEKSI';

    public const ROLE_ROW = 'ROW';

    // ADMIN satu-satunya role Web Admin; empat role lapangan hanya untuk aplikasi Android (API).
    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Admin (Web Admin)',
        self::ROLE_POSKO => 'POSKO (Android)',
        self::ROLE_HARSUS => 'HARSUS (Android)',
        self::ROLE_INSPEKSI => 'INSPEKSI (Android)',
        self::ROLE_ROW => 'ROW (Android)',
    ];

    public const FIELD_ROLES = [self::ROLE_POSKO, self::ROLE_HARSUS, self::ROLE_INSPEKSI, self::ROLE_ROW];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function fieldTeam(): BelongsTo
    {
        return $this->belongsTo(FieldTeam::class);
    }

    public function canAccessAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->is_active;
    }

    public function isFieldAccount(): bool
    {
        return in_array($this->role, self::FIELD_ROLES, true);
    }

    /**
     * Akun lapangan boleh memakai API bila aktif dan timnya aktif dengan role yang sama.
     */
    public function canUseFieldApi(): bool
    {
        $team = $this->fieldTeam;

        return $this->isFieldAccount()
            && $this->is_active
            && $team !== null
            && $team->is_active
            && $team->role === $this->role;
    }

    public function displayName(): string
    {
        return $this->username ?? $this->name;
    }
}
