<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_real_active_admin_with_a_hashed_password(): void
    {
        $password = 'LocalOnly9!';

        $this->artisan('admin:create', [
            '--username' => 'command_admin',
            '--email' => 'command.admin@example.test',
        ])
            ->expectsQuestion('Password Admin', $password)
            ->expectsQuestion('Ulangi password Admin', $password)
            ->expectsOutput('Akun ADMIN COMMAND_ADMIN berhasil dibuat dan aktif.')
            ->assertSuccessful();

        $admin = User::query()->where('username', 'COMMAND_ADMIN')->firstOrFail();

        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertNotSame($password, $admin->password);
    }
}
