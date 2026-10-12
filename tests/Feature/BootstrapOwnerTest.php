<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerCode;
use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BootstrapOwnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Re-issuing returns a fresh plaintext while setup is unfinished - the same
     * path Setup uses when a printed code is lost.
     */
    private function setupSecret(): string
    {
        return app(StoreInstallationService::class)->reissueSecret();
    }

    public function test_first_run_is_setup_only_and_bootstrap_is_deleted_after_email_verification(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $bootstrap = User::sole();
        $this->assertTrue($bootstrap->is_bootstrap);

        $secret = $this->setupSecret();

        // The secret is stored as a hash, never in the clear.
        $this->assertNotSame($secret, $bootstrap->fresh()->bootstrap_secret_hash);
        $this->assertTrue(Hash::check($secret, $bootstrap->fresh()->bootstrap_secret_hash));

        $this->postJson('/api/auth/setup-login', ['secret' => 'ZZZZZZZZZZZZ'])->assertUnauthorized();
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertOk();
        $this->assertGuest();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->get('/admin')->assertRedirect('/owner/login');
        $this->postJson('/api/auth/setup-owner', ['station_name' => 'My Water Station', 'name' => 'My Owner', 'email' => 'real@example.com', 'password' => 'MyPassword123', 'password_confirmation' => 'MyPassword123'])->assertOk();
        $this->assertNotSame('My Water Station', \App\Models\SystemSetting::value('station_name'));
        $this->assertGuest();

        // Supplying details consumes the secret, so it cannot be replayed to
        // overwrite the details with a different owner's address.
        $this->assertNull($bootstrap->fresh()->bootstrap_secret_hash);
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertUnauthorized();

        $this->postJson('/api/auth/login-owner', ['email' => 'real@example.com', 'password' => 'MyPassword123'])->assertUnauthorized();
        $job = Queue::pushed(SendOwnerCode::class)->last();
        $this->postJson('/api/auth/setup-verify', ['code' => $job->code])->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->assertDatabaseMissing('users', ['id' => $bootstrap->id]);
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs(User::sole());
        $this->assertFalse(User::sole()->is_bootstrap);
        $this->assertSame('My Water Station', \App\Models\SystemSetting::value('station_name'));
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertUnauthorized();
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);

        // Setup is permanently closed once a real owner exists: no code can ever be
        // re-issued for this store again.
        $this->assertNull(app(StoreInstallationService::class)->reissueSecret());
    }

    public function test_the_credentials_published_by_earlier_releases_no_longer_work(): void
    {
        // These three literals shipped in AdminSetupService and were printed in the
        // installer guide, so every deployment shared them. They must be dead.
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->setupSecret();

        foreach (['571067', 'Adm1nP4ss!123', 'admin@aquaflow.com'] as $retired) {
            $this->postJson('/api/auth/setup-login', ['secret' => $retired])->assertUnauthorized();
            $this->postJson('/api/auth/setup-login', ['email' => 'admin@aquaflow.com', 'password' => 'Adm1nP4ss!123', 'code' => $retired])->assertUnprocessable();
        }

        $this->assertTrue(User::sole()->is_bootstrap);
    }

    public function test_rerunning_install_issues_a_new_code_and_invalidates_the_previous_one(): void
    {
        // A one-shot code that is printed once and never replacable would strand a
        // store whose owner mistyped it or lost the console output.
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $first = $this->setupSecret();
        $second = $this->setupSecret();

        $this->assertNotSame($first, $second);
        $this->postJson('/api/auth/setup-login', ['secret' => $first])->assertUnauthorized();
        $this->postJson('/api/auth/setup-login', ['secret' => $second])->assertOk();
    }

    public function test_setup_requires_bootstrap_session_and_never_opens_on_existing_stores(): void
    {
        $this->postJson('/api/auth/setup-owner', [])->assertForbidden();
        $this->postJson('/api/auth/setup-verify', ['code' => '571067'])->assertForbidden();
        $this->seed();
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['is_bootstrap' => true]);
        $this->postJson('/api/auth/setup-login', ['secret' => 'ZZZZZZZZZZZZ'])->assertUnauthorized();
    }

    public function test_invalid_registration_and_expired_setup_do_not_replace_the_bootstrap_account(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $secret = $this->setupSecret();
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();

        // The placeholder address the installer stamps on the bootstrap row is not
        // an acceptable owner address, and the weak password is rejected too.
        $this->postJson('/api/auth/setup-owner', ['name' => 'Owner', 'email' => 'SETUP@AQUAFLOW.INVALID', 'password' => '1234', 'password_confirmation' => '1234'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertTrue(User::sole()->is_bootstrap);
        $this->postJson('/api/auth/setup-verify', ['code' => '571067'])->assertUnprocessable();
        $this->assertGuest();
        $this->travel(16)->minutes();
        $this->postJson('/api/auth/setup-owner', ['name' => 'Owner', 'email' => 'private@example.com', 'password' => 'ValidPassword123', 'password_confirmation' => 'ValidPassword123'])->assertForbidden();
        $this->assertTrue(User::sole()->is_bootstrap);
    }
}
