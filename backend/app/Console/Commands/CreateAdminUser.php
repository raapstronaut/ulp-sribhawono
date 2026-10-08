<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {--username= : Username Admin}
                            {--email= : Email administrasi untuk kolom users}';

    protected $description = 'Membuat akun ADMIN aktif secara interaktif tanpa password bawaan';

    public function handle(): int
    {
        $username = Str::upper(trim((string) ($this->option('username') ?: $this->ask('Username Admin'))));
        $email = trim((string) ($this->option('email') ?: $this->ask('Email administrasi')));
        $password = (string) $this->secret('Password Admin');
        $passwordConfirmation = (string) $this->secret('Ulangi password Admin');

        $passwordRule = app()->environment(['local', 'testing'])
            ? Password::min(8)
            : Password::min(12)->mixedCase()->letters()->numbers()->symbols();

        $validator = Validator::make([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', $passwordRule],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (app()->environment('local')) {
            $this->warn('Password development bersifat sementara. Ganti dengan password kuat sebelum deployment.');
        }

        $admin = new User;
        $admin->forceFill([
            'name' => $username,
            'email' => $email,
            'username' => $username,
            'password' => Hash::make($password),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $admin->save();

        $this->info("Akun ADMIN {$username} berhasil dibuat dan aktif.");

        return self::SUCCESS;
    }
}
