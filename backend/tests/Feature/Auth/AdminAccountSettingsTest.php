<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'CurrentPass!123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_admin_can_change_username_and_use_it_for_the_next_login(): void
    {
        $admin = $this->createAdmin('OLD_USERNAME');

        $response = $this->actingAs($admin)->patch('/admin/account/username', [
            'username' => 'new_username',
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('status', 'username-updated');
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'username' => 'NEW_USERNAME',
        ]);

        $this->post('/logout');

        $this->post('/login', [
            'username' => 'OLD_USERNAME',
            'password' => self::CURRENT_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->post('/login', [
            'username' => 'NEW_USERNAME',
            'password' => self::CURRENT_PASSWORD,
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_username_must_be_unique(): void
    {
        $admin = $this->createAdmin('FIRST_ADMIN');
        $this->createAdmin('SECOND_ADMIN');

        $response = $this->actingAs($admin)
            ->from('/admin/account')
            ->patch('/admin/account/username', [
                'username' => 'second_admin',
            ]);

        $response
            ->assertRedirect('/admin/account')
            ->assertSessionHasErrors('username', null, 'updateUsername');
        $this->assertSame('FIRST_ADMIN', $admin->fresh()->username);
    }

    public function test_wrong_current_password_prevents_password_change(): void
    {
        $admin = $this->createAdmin('PASSWORD_ADMIN');

        $response = $this->actingAs($admin)
            ->from('/admin/account')
            ->put('/admin/account/password', [
                'current_password' => 'WrongCurrent!999',
                'password' => 'ReplacementPass!456',
                'password_confirmation' => 'ReplacementPass!456',
            ]);

        $response
            ->assertRedirect('/admin/account')
            ->assertSessionHasErrors('current_password', null, 'updatePassword');
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $admin->fresh()->password));
    }

    public function test_new_password_must_be_strong(): void
    {
        $admin = $this->createAdmin('WEAK_PASSWORD_ADMIN');

        $response = $this->actingAs($admin)
            ->from('/admin/account')
            ->put('/admin/account/password', [
                'current_password' => self::CURRENT_PASSWORD,
                'password' => 'weakpassword',
                'password_confirmation' => 'weakpassword',
            ]);

        $response
            ->assertRedirect('/admin/account')
            ->assertSessionHasErrors('password', null, 'updatePassword');
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $admin->fresh()->password));
    }

    public function test_admin_can_change_password_and_use_it_for_the_next_login(): void
    {
        $admin = $this->createAdmin('CHANGE_PASSWORD_ADMIN');
        $newPassword = 'ReplacementPass!456';

        $response = $this->actingAs($admin)->put('/admin/account/password', [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('status', 'password-updated');
        $this->assertTrue(Hash::check($newPassword, $admin->fresh()->password));

        $this->post('/logout');

        $this->post('/login', [
            'username' => $admin->username,
            'password' => self::CURRENT_PASSWORD,
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->post('/login', [
            'username' => $admin->username,
            'password' => $newPassword,
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    private function createAdmin(string $username): User
    {
        return User::factory()->admin()->create([
            'username' => $username,
            'password' => Hash::make(self::CURRENT_PASSWORD),
        ]);
    }
}
