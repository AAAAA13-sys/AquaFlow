<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BootstrapOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_is_setup_only_and_bootstrap_is_deleted_after_email_verification(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $bootstrap = User::sole();
        $this->assertTrue($bootstrap->is_bootstrap);
        $this->postJson('/api/auth/setup-login', ['email' => 'admin@aquaflow.com', 'password' => 'Adm1nP4ss!123', 'code' => '000000'])->assertUnauthorized();
        $this->postJson('/api/auth/setup-login', ['email' => 'admin@aquaflow.com', 'password' => 'Adm1nP4ss!123', 'code' => '571067'])->assertOk();
        $this->assertGuest();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->get('/admin')->assertRedirect('/owner/login');
        $this->postJson('/api/auth/setup-owner', ['station_name' => 'My Water Station', 'name' => 'My Owner', 'email' => 'real@example.com', 'password' => 'MyPassword123', 'password_confirmation' => 'MyPassword123'])->assertOk();
        $this->assertNotSame('My Water Station', \App\Models\SystemSetting::value('station_name'));
        $this->assertGuest();
        $this->postJson('/api/auth/login-owner', ['email' => 'real@example.com', 'password' => 'MyPassword123'])->assertUnauthorized();
        $job = Queue::pushed(SendOwnerCode::class)->last();
        $this->postJson('/api/auth/setup-verify', ['code' => $job->code])->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->assertDatabaseMissing('users', ['id' => $bootstrap->id]);
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs(User::sole());
        $this->assertFalse(User::sole()->is_bootstrap);
        $this->assertSame('My Water Station', \App\Models\SystemSetting::value('station_name'));
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/setup-login', ['email' => 'admin@aquaflow.com', 'password' => 'Adm1nP4ss!123', 'code' => '571067'])->assertUnauthorized();
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_setup_requires_bootstrap_session_and_never_opens_on_existing_stores(): void
    {
        $this->postJson('/api/auth/setup-owner', [])->assertForbidden();
        $this->postJson('/api/auth/setup-verify', ['code' => '571067'])->assertForbidden();
        $this->seed();
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['is_bootstrap' => true]);
        $this->postJson('/api/auth/setup-login', ['email' => 'admin@aquaflow.com', 'password' => 'Adm1nP4ss!123', 'code' => '571067'])->assertUnauthorized();
    }

    public function test_invalid_registration_and_expired_setup_do_not_replace_the_bootstrap_account(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->postJson('/api/auth/setup-login', ['email' => 'admin@aquaflow.com', 'password' => 'Adm1nP4ss!123', 'code' => '571067'])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->postJson('/api/auth/setup-owner', ['name' => 'Owner', 'email' => 'ADMIN@AQUAFLOW.COM', 'password' => '1234', 'password_confirmation' => '1234'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertTrue(User::sole()->is_bootstrap);
        $this->postJson('/api/auth/setup-verify', ['code' => '571067'])->assertUnprocessable();
        $this->assertGuest();
        $this->travel(16)->minutes();
        $this->postJson('/api/auth/setup-owner', ['name' => 'Owner', 'email' => 'private@example.com', 'password' => 'ValidPassword123', 'password_confirmation' => 'ValidPassword123'])->assertForbidden();
        $this->assertTrue(User::sole()->is_bootstrap);
    }
}
