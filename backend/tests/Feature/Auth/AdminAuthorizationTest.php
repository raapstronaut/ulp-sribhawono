<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_cannot_open_any_existing_admin_page(): void
    {
        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_non_admin_user_cannot_open_any_existing_admin_page(): void
    {
        $user = User::factory()->create([
            'username' => 'ROW_ACCOUNT',
            'role' => 'ROW',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_inactive_admin_with_existing_session_is_denied(): void
    {
        $admin = User::factory()->inactiveAdmin()->create([
            'username' => 'DISABLED_ADMIN',
        ]);

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    public function test_active_admin_can_open_dashboard_and_header_shows_username_and_logout(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'HEADER_ADMIN',
            'password' => Hash::make('HeaderPass!123'),
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response
            ->assertOk()
            ->assertSee('HEADER_ADMIN')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false);
    }

    /**
     * @return array<int, string>
     */
    private function adminUrls(): array
    {
        return [
            '/admin/dashboard',
            '/admin/p0',
            '/admin/p0/example',
            '/admin/work-orders',
            '/admin/work-orders/example',
            '/admin/account',
        ];
    }
}
