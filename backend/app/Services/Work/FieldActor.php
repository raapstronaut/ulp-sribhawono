<?php

namespace App\Services\Work;

use App\Models\FieldTeam;
use App\Models\User;

/**
 * Pelaku tindakan lapangan: akun tim lewat API mobile, atau Admin yang mencatat atas
 * nama tim di Web Admin (misalnya saat aplikasi Android belum tersedia).
 */
final class FieldActor
{
    private function __construct(
        public readonly User $user,
        public readonly ?FieldTeam $team,
        public readonly bool $onBehalfByAdmin,
    ) {}

    public static function mobile(User $account): self
    {
        return new self($account, $account->fieldTeam, false);
    }

    public static function admin(User $admin, ?FieldTeam $team = null): self
    {
        return new self($admin, $team, true);
    }

    public function channel(): string
    {
        return $this->onBehalfByAdmin ? 'ADMIN_WEB' : 'MOBILE';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter([
            'channel' => $this->channel(),
            'field_team_id' => $this->team?->getKey(),
        ], fn ($value) => $value !== null);
    }
}
