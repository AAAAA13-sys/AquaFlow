<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_page_renders_with_a_csrf_token(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Cashier Terminal');
        $response->assertSee('csrf-token', false);
    }

    public function test_owner_login_page_renders(): void
    {
        $this->get('/owner/login')
            ->assertOk()
            ->assertSee('Owner PIN');
    }

    public function test_cashier_can_log_in(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'cashier',
            'password' => '1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role', 'cashier');

        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/auth/login', [
            'username' => 'cashier',
            'password' => 'wrong',
        ])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_owner_is_rejected_on_the_cashier_login(): void
    {
        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => '1234',
        ])->assertStatus(403);

        $this->assertGuest();
    }

    public function test_owner_can_log_in_with_the_pin(): void
    {
        $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => '1234',
            'pin' => '2468',
        ])->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_wrong_owner_pin_is_rejected(): void
    {
        $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => '1234',
            'pin' => '0000',
        ])->assertStatus(401);
    }

    public function test_protected_endpoints_require_a_session(): void
    {
        $this->getJson('/api/bootstrap')->assertStatus(401);
        $this->getJson('/api/customers')->assertStatus(401);
        $this->postJson('/api/transactions', [])->assertStatus(401);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $cashier = User::query()->where('username', 'cashier')->firstOrFail();

        $this->actingAs($cashier)->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest();

        $this->getJson('/api/bootstrap')->assertStatus(401);
    }

    public function test_no_password_hashes_are_exposed(): void
    {
        $owner = User::query()->where('username', 'admin')->firstOrFail();

        $response = $this->actingAs($owner)->getJson('/api/bootstrap');

        $response->assertOk();
        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    public function test_cashier_never_receives_the_user_list(): void
    {
        $cashier = User::query()->where('username', 'cashier')->firstOrFail();

        $this->actingAs($cashier)
            ->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonMissingPath('users');
    }
}
