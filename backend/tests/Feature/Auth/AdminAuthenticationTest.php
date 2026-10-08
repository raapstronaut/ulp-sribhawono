<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CurrentPass!123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_page_is_available_without_public_registration(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSee('Login Admin')
            ->assertSee('Username wajib menggunakan huruf kapital')
            ->assertSee('name="_token"', false)
            ->assertDontSee('Daftar Akun');

        $this->assertFalse(app('router')->has('register'));
    }

    public function test_active_admin_can_login_with_exact_username_case(): void
    {
        $admin = $this->createAdmin('ADMIN');

        $response = $this->post('/login', [
            'username' => 'ADMIN',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_username_case_is_rejected_without_normalization(): void
    {
        $this->createAdmin('ADMIN');

        foreach (['admin', 'Admin', 'AdMiN'] as $username) {
            $response = $this->post('/login', [
                'username' => $username,
                'password' => self::PASSWORD,
            ]);

            $response->assertSessionHasErrors([
                'username' => 'Username atau password tidak valid.',
            ]);
            $this->assertGuest();
        }
    }

    public function test_admin_is_redirected_to_originally_requested_admin_page(): void
    {
        $admin = $this->createAdmin('INTENDED_ADMIN');

        $this->get('/admin/account')->assertRedirect(route('login'));

        $response = $this->post('/login', [
            'username' => $admin->username,
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect('/admin/account');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->createAdmin('WRONG_PASSWORD_ADMIN');

        $response = $this->from('/login')->post('/login', [
            'username' => 'WRONG_PASSWORD_ADMIN',
            'password' => 'IncorrectPass!999',
        ]);

        $response
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_admin_is_rejected(): void
    {
        User::factory()->inactiveAdmin()->create([
            'username' => 'INACTIVE_ADMIN',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $response = $this->post('/login', [
            'username' => 'INACTIVE_ADMIN',
            'password' => self::PASSWORD,
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_non_admin_role_is_rejected_by_web_login(): void
    {
        User::factory()->create([
            'username' => 'POSKO_ACCOUNT',
            'role' => 'POSKO',
            'is_active' => true,
            'password' => Hash::make(self::PASSWORD),
        ]);

        $response = $this->post('/login', [
            'username' => 'POSKO_ACCOUNT',
            'password' => self::PASSWORD,
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $username = 'RATE_LIMIT_ADMIN';
        $key = 'rate_limit_admin|127.0.0.1';
        RateLimiter::clear($key);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'username' => $username,
                'password' => 'IncorrectPass!999',
            ]);
        }

        $response = $this->post('/login', [
            'username' => $username,
            'password' => 'IncorrectPass!999',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 5));
        $this->assertGuest();
    }

    public function test_logout_ends_the_session_and_only_accepts_post(): void
    {
        $admin = $this->createAdmin('LOGOUT_ADMIN');

        $this->actingAs($admin);

        $this->get('/logout')->assertStatus(405);

        $response = $this->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    private function createAdmin(string $username): User
    {
        return User::factory()->admin()->create([
            'username' => $username,
            'password' => Hash::make(self::PASSWORD),
        ]);
    }
}
