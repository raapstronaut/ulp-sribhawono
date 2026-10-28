<?php

namespace App\Console\Support;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Autentikasi akun ADMIN aktif untuk perintah CLI yang mengubah data atas nama Admin
 * (mis. apkuk:reconcile --apply). Kata sandi hanya diminta secara interaktif (tersembunyi),
 * tidak pernah lewat argumen atau variabel lingkungan, sehingga tidak tersimpan di riwayat shell.
 */
class ConsoleAdminAuthenticator
{
    /**
     * @throws AuthenticationException
     */
    public function authenticate(Command $command, ?string $username): User
    {
        $username = Str::upper(trim((string) $username));
        if ($username === '') {
            throw new AuthenticationException('--admin wajib diisi dengan username akun ADMIN aktif. Tidak ada perubahan.');
        }

        $password = $this->readPassword($command, $username);
        if ($password === null || $password === '') {
            throw new AuthenticationException('Kata sandi Admin wajib dimasukkan secara interaktif (tidak diterima lewat argumen). Tidak ada perubahan.');
        }

        $user = User::query()->where('username', $username)->first();
        if ($user === null || ! Hash::check($password, (string) $user->password) || ! $user->canAccessAdmin()) {
            throw new AuthenticationException('Autentikasi Admin gagal: username/kata sandi salah atau akun bukan ADMIN aktif. Tidak ada perubahan.');
        }

        return $user;
    }

    protected function readPassword(Command $command, string $username): ?string
    {
        return $command->secret("Kata sandi akun Admin {$username}");
    }
}
