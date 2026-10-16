<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_first_run_is_setup_only_and_bootstrap_is_deleted_when_the_owner_is_created(): void
    {
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
        $this->get('/admin')->assertRedirect('/');

        $this->postJson('/api/auth/setup-owner', [
            'station_name' => 'My Water Station',
            'name' => 'My Owner',
            'username' => 'stationowner',
            'email' => 'real@example.com',
            'password' => 'MyPassword123',
            'password_confirmation' => 'MyPassword123',
        ])->assertOk();

        // Supplying details consumes the secret with the bootstrap row itself, so
        // it cannot be replayed to overwrite the account with different credentials.
        $this->assertNull($bootstrap->fresh());
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertUnauthorized();

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs(User::sole());
        $this->assertFalse(User::sole()->is_bootstrap);
        $this->assertSame('My Water Station', SystemSetting::value('station_name'));

        // The owner now signs in with the username they just chose - no code step.
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login-owner', ['username' => 'stationowner', 'password' => 'MyPassword123'])->assertOk();
        $this->assertAuthenticatedAs(User::sole());

        $this->postJson('/api/auth/logout');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertUnauthorized();
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);

        // Setup is permanently closed once a real owner exists: no code can ever be
        // re-issued for this store again.
        $this->assertNull(app(StoreInstallationService::class)->reissueSecret());
    }

    public function test_owner_email_is_required_at_setup(): void
    {
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->postJson('/api/auth/setup-login', ['secret' => $this->setupSecret()])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();

        $this->postJson('/api/auth/setup-owner', [
            'station_name' => 'No Mail Station',
            'name' => 'Owner',
            'username' => 'ownername',
            'password' => 'MyPassword123',
            'password_confirmation' => 'MyPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(User::sole()->is_bootstrap);
    }

    public function test_the_credentials_published_by_earlier_releases_no_longer_work(): void
    {
        // These three literals shipped in AdminSetupService and were printed in the
        // installer guide, so every deployment shared them. They must be dead.
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->setupSecret();

        foreach (['571067', 'Adm1nP4ss!123', 'admin@aquaflow.com'] as $retired) {
            $this->postJson('/api/auth/setup-login', ['secret' => $retired])->assertUnauthorized();
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
        $this->seed();
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['is_bootstrap' => true]);
        $this->postJson('/api/auth/setup-login', ['secret' => 'ZZZZZZZZZZZZ'])->assertUnauthorized();
    }

    public function test_invalid_registration_and_expired_setup_do_not_replace_the_bootstrap_account(): void
    {
        $this->artisan('aquaflow:install-store')->assertSuccessful();
        $secret = $this->setupSecret();
        $this->postJson('/api/auth/setup-login', ['secret' => $secret])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();

        // The username is required and unique, and the weak password is rejected.
        $this->postJson('/api/auth/setup-owner', ['station_name' => 'S', 'name' => 'Owner', 'password' => '1234', 'password_confirmation' => '1234'])->assertUnprocessable();
        $this->assertTrue(User::sole()->is_bootstrap);

        $this->travel(16)->minutes();
        $this->postJson('/api/auth/setup-owner', [
            'station_name' => 'S', 'name' => 'Owner', 'username' => 'ownername',
            'password' => 'ValidPassword123', 'password_confirmation' => 'ValidPassword123',
        ])->assertForbidden();
        $this->assertTrue(User::sole()->is_bootstrap);
    }
}
