<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        $response->assertSee('Employee Login')->assertDontSee('id="otpForm"', false)->assertSee('/owner/login', false);
        $response->assertSee('csrf-token', false);
    }

    public function test_owner_login_page_renders(): void
    {
        $this->get('/owner/login')
            ->assertOk()
            ->assertSee('Admin Login')->assertSee('Admin username')
            ->assertDontSee('Email verification code')
            ->assertDontSee('id="pin"', false);
    }

    public function test_owner_signs_in_with_username_and_password(): void
    {
        $response = $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => '1234',
        ]);

        $response->assertOk()->assertJsonPath('user.role', 'admin');
        // No code step: the session is open the moment the credentials check out.
        $response->assertJsonMissingPath('recovery_codes');

        $this->assertAuthenticated();
    }

    public function test_owner_login_still_refuses_the_wrong_password(): void
    {
        $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => 'wrong',
        ])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_cashier_is_rejected_on_the_owner_login(): void
    {
        $this->postJson('/api/auth/login-owner', [
            'username' => 'cashier',
            'password' => '1234',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid username or password.']);

        $this->assertGuest();
    }

    public function test_owner_portal_routes_reject_a_cashier_session(): void
    {
        $cashier = User::query()->where('username', 'cashier')->firstOrFail();

        // The page you signed in at is not what grants access - the role is.
        $this->actingAs($cashier)->get('/admin/inventory')->assertRedirect('/cashier');
        $this->actingAs($cashier)->getJson('/api/users')->assertForbidden();
    }

    public function test_u_s01_cashier_can_log_in(): void
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
        ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid username or password.']);

        $this->assertGuest();
    }

    public function test_protected_endpoints_require_a_session(): void
    {
        $this->getJson('/api/bootstrap')->assertStatus(401);
        $this->getJson('/api/customers')->assertStatus(401);
        $this->postJson('/api/transactions', ['submission_key' => (string) Str::uuid()])->assertStatus(401);
    }

    public function test_u_s05_logout_invalidates_the_session(): void
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
