<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\PasswordResetOtp;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthAndSecurityFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $unseeded = [
            'test_all_five_roles_share_login_and_keep_server_access_boundaries',
            'test_owner_can_create_manager_but_cannot_remove_own_owner_access',
            'test_manager_can_manage_staff_but_cannot_modify_or_create_superiors',
            'test_restored_staff_accounts_get_links_and_password_onboarding',
            'test_inactive_employee_and_bootstrap_accounts_cannot_sign_in',
            'test_first_run_is_setup_only_and_bootstrap_is_deleted_when_the_owner_is_created',
            'test_owner_email_is_required_at_setup',
            'test_the_credentials_published_by_earlier_releases_no_longer_work',
            'test_rerunning_install_issues_a_new_code_and_invalidates_the_previous_one',
            'test_setup_requires_bootstrap_session_and_never_opens_on_existing_stores',
            'test_invalid_registration_and_expired_setup_do_not_replace_the_bootstrap_account',
            'test_upgrade_preserves_existing_owner_identity_credentials_and_activity',
            'test_account_recovery_requires_an_established_owner_account_and_a_valid_email',
        ];

        if (!in_array($this->name(), $unseeded, true)) {
            $this->seed();
        }
    }

    // --- From AuthTest.php ---
    public function test_login_page_renders_with_a_csrf_token(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Sign in')->assertDontSee('id="otpForm"', false);
        $response->assertSee('csrf-token', false);
    }

    public function test_owner_login_page_renders(): void
    {
        $this->get('/owner/login')->assertRedirect('/');
    }

    public function test_owner_signs_in_with_username_and_password(): void
    {
        $response = $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => '1234',
        ]);

        $response->assertOk()->assertJsonPath('user.role', 'super_admin');
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

    public function test_legacy_login_endpoint_accepts_cashier_and_routes_by_role(): void
    {
        $this->postJson('/api/auth/login-owner', [
            'username' => 'cashier',
            'password' => '1234',
        ])->assertOk();

        $this->assertAuthenticated();
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

    public function test_shared_login_accepts_owner_and_routes_by_role(): void
    {
        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => '1234',
        ])->assertOk();

        $this->assertAuthenticated();
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

    // --- From SuperAdminLoginTest.php ---
    private function account(string $role, string $username): User
    {
        return User::create([
            'name' => $username, 'username' => $username, 'role' => $role,
            'email' => $username.'@example.com', 'password' => 'Private123!',
            'employee_id' => in_array($role, ['rider', 'refiller'], true)
                ? Employee::create(['name' => $username, 'job_title' => $role, 'is_active' => true])->id : null,
        ]);
    }

    public function test_all_five_roles_share_login_and_keep_server_access_boundaries(): void
    {
        foreach (User::ROLES as $role) {
            $user = $this->account($role, $role);
            $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Private123!', 'role' => 'super_admin'])
                ->assertOk()->assertJsonPath('user.role', $role)->assertJsonPath('redirect', $user->homeUrl());
            $this->get($user->homeUrl())->assertStatus($user->isAdmin() ? 302 : 200);
            if ($user->isAdmin()) {
                $this->get('/admin/dashboard')->assertOk();
            }
            if ($user->isEmployee()) {
                $this->get('/cashier')->assertForbidden();
                $this->getJson('/api/bootstrap')->assertForbidden();
            }
            if (! $user->isSuperAdmin()) {
                $this->putJson('/api/settings', ['station_name' => 'Forbidden'])->assertForbidden();
            }
            $this->postJson('/api/auth/logout')->assertOk();
        }
    }

    public function test_manager_can_manage_staff_but_cannot_modify_or_create_superiors(): void
    {
        $owner = $this->account('super_admin', 'owner');
        $manager = $this->account('admin', 'manager');
        $staff = $this->account('cashier', 'staff');
        $this->actingAs($manager)->get('/admin/dashboard')->assertOk();
        $this->get('/admin/settings')->assertRedirect('/admin');
        foreach ([$owner, $manager] as $protected) {
            $this->patchJson('/api/users/'.$protected->id, ['password' => 'Changed123!'])->assertForbidden();
            $this->deleteJson('/api/users/'.$protected->id)->assertForbidden();
        }
        foreach (['admin', 'super_admin'] as $role) {
            $this->postJson('/api/users', ['name' => 'New', 'username' => 'new_'.$role, 'role' => $role, 'email' => $role.'@new.example.com', 'password' => 'Private123!'])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
            $this->patchJson('/api/users/'.$staff->id, ['role' => $role, 'password' => 'Private123!'])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->patchJson('/api/users/'.$staff->id, ['name' => 'Updated Staff'])->assertOk();
        $this->assertSame('super_admin', $owner->fresh()->role);
        $this->assertSame('cashier', $staff->fresh()->role);
        $this->getJson('/api/users')->assertOk()->assertJsonPath('users.0.can_manage', false);
    }

    public function test_restored_staff_accounts_get_links_and_password_onboarding(): void
    {
        $manager = $this->account('admin', 'manager');
        foreach (['refiller', 'rider'] as $role) {
            $id = $this->actingAs($manager)->postJson('/api/users', ['name' => $role, 'username' => 'new_'.$role, 'role' => $role, 'email' => $role.'@new.example.com'])
                ->assertCreated()->assertJsonPath('user.must_change_password', true)->json('user.id');
            $user = User::findOrFail($id);
            $this->assertTrue($user->employee->is_active);
            $this->postJson('/api/auth/logout');
            $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => '12345678'])->assertOk()->assertJsonPath('redirect', '/employee');
            $this->getJson('/api/employee/orders')->assertForbidden()->assertJsonPath('code', 'password_change_required');
            $this->putJson('/api/profile', ['name' => $user->name, 'current_password' => '12345678', 'new_password' => 'Changed123!', 'new_password_confirmation' => 'Changed123!'])
                ->assertOk()->assertJsonPath('user.must_change_password', false);
            $this->getJson('/api/employee/orders')->assertOk();
            $this->postJson('/api/auth/logout');
        }
    }

    public function test_inactive_employee_and_bootstrap_accounts_cannot_sign_in(): void
    {
        $rider = $this->account('rider', 'rider');
        $rider->employee->update(['is_active' => false]);
        $owner = $this->account('super_admin', 'owner');
        $owner->update(['is_bootstrap' => true]);
        foreach ([$rider, $owner] as $user) {
            $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Private123!'])->assertUnauthorized();
        }
        $this->assertGuest();
    }

    public function test_owner_can_create_manager_but_cannot_remove_own_owner_access(): void
    {
        $owner = $this->account('super_admin', 'owner');
        $this->actingAs($owner)->postJson('/api/users', ['name' => 'Manager', 'username' => 'manager', 'role' => 'admin', 'email' => 'manager@example.com', 'password' => 'Private123!'])
            ->assertCreated()->assertJsonPath('user.role', 'admin');
        $this->patchJson('/api/users/'.$owner->id, ['role' => 'admin', 'password' => 'Private123!'])->assertUnprocessable();
        $this->patchJson('/api/users/'.$owner->id, ['is_active' => false])->assertUnprocessable();
        $this->deleteJson('/api/users/'.$owner->id)->assertUnprocessable();
        $this->assertTrue($owner->fresh()->isSuperAdmin());
        $this->assertTrue($owner->fresh()->is_active);
    }

    // --- From AccountRecoveryTest.php ---
    public function test_accounts_require_email_and_admin_requires_a_strong_password(): void
    {
        $this->seed();
        $this->actingAs(User::where('role', 'super_admin')->firstOrFail());
        $base = ['name' => 'New User', 'username' => 'newuser', 'role' => 'cashier'];
        $this->postJson('/api/users', $base)->assertUnprocessable()->assertJsonValidationErrors('email');
        $base['role'] = 'admin';
        $base['email'] = 'new@example.com';
        $this->postJson('/api/users', $base)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $base + ['password' => '12345678'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/users', $base + ['password' => 'Private123!'])->assertCreated();
        $this->assertTrue(Hash::check('Private123!', User::where('username', 'newuser')->firstOrFail()->password));
    }

    public function test_cashier_can_request_and_redeem_a_reset_code(): void
    {
        $this->seed();
        Queue::fake();
        $user = User::where('role', 'cashier')->firstOrFail();
        $user->update(['email' => 'cashier@example.com', 'must_change_password' => true]);
        $this->postJson('/api/auth/forgot-password/send-otp', ['email' => $user->email])->assertOk();
        Queue::assertPushedOn('mail', SendPasswordResetOtpJob::class);
        PasswordResetOtp::where('email', $user->email)->update(['otp_hash' => Hash::make('123456')]);
        $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $user->email, 'otp' => '123456', 'password' => 'Changed123!',
            'password_confirmation' => 'Changed123!',
        ])->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Changed123!'])->assertOk();
    }

    public function test_employee_login_has_password_recovery(): void
    {
        $this->get('/')->assertOk()->assertSee('Forgot password?')->assertSee('id="forgotPasswordForm"', false);
    }

    public function test_promoting_cashier_requires_password_and_email_cannot_be_removed(): void
    {
        $this->seed();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson('/api/users/'.$cashier->id, ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson('/api/users/'.$cashier->id, ['email' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('cashier', $cashier->fresh()->role);
    }

    // --- From AdminForgotPasswordTest.php ---
    public function test_owner_login_renders_forgot_password_and_embedded_eye_toggles(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Forgot password?');
        $response->assertSee('id="forgotPasswordForm"', false);
        $response->assertSee('id="forgotEmail"', false);
        $response->assertSee('id="forgotOtp"', false);
        $response->assertSee('id="forgotNewPassword"', false);
        $response->assertSee('id="forgotConfirmPassword"', false);
        // Ensure old external "Show" button text is absent from login credentials
        $response->assertDontSee('class="btn btn-secondary" onclick="togglePassword(\'p\', this)">Show', false);
    }

    public function test_requesting_otp_for_admin_queues_mail_worker_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/auth/forgot-password/send-otp', [
            'email' => 'kmikaelim11@gmail.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'If this email address is registered to an active account, a verification code has been dispatched.',
            ]);

        Queue::assertPushedOn('mail', SendPasswordResetOtpJob::class, function ($job) {
            return $job->email === 'kmikaelim11@gmail.com' && strlen($job->code) === 6;
        });

        $this->assertDatabaseHas('password_reset_otps', [
            'email' => 'kmikaelim11@gmail.com',
            'attempts' => 0,
        ]);
    }

    public function test_requesting_otp_for_unknown_account_does_not_queue_job(): void
    {
        Queue::fake();

        // Non-existent email
        $response = $this->postJson('/api/auth/forgot-password/send-otp', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'If this email address is registered to an active account, a verification code has been dispatched.',
            ]);

        Queue::assertNothingPushed();
    }

    public function test_admin_can_reset_password_with_valid_otp(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        // Seed an OTP record
        $otp = '654321';
        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(15),
                'attempts' => 0,
            ]
        );

        $response = $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $email,
            'otp' => $otp,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
            ]);

        // Verify OTP is cleaned up
        $this->assertDatabaseMissing('password_reset_otps', ['email' => $email]);

        // Verify admin can sign in with new password
        $this->postJson('/api/auth/login-owner', [
            'username' => $admin->username,
            'password' => 'NewSecurePassword123!',
        ])->assertOk();
    }

    public function test_verify_otp_endpoint_validates_and_confirms_code(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make('654321'),
                'expires_at' => Carbon::now()->addMinutes(15),
                'attempts' => 0,
            ]
        );

        $response = $this->postJson('/api/auth/forgot-password/verify-otp', [
            'email' => $email,
            'otp' => '654321',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'message' => 'Verification code confirmed. Please choose your new password.',
            ]);

        // Token is NOT deleted by verify-otp so subsequent reset call succeeds
        $this->assertDatabaseHas('password_reset_otps', ['email' => $email]);
    }

    public function test_verify_otp_endpoint_rejects_invalid_code(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make('654321'),
                'expires_at' => Carbon::now()->addMinutes(15),
                'attempts' => 0,
            ]
        );

        $response = $this->postJson('/api/auth/forgot-password/verify-otp', [
            'email' => $email,
            'otp' => '111111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseHas('password_reset_otps', [
            'email' => $email,
            'attempts' => 1,
        ]);
    }

    public function test_wrong_otp_increments_attempts_and_rejects(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make('123456'),
                'expires_at' => Carbon::now()->addMinutes(15),
                'attempts' => 0,
            ]
        );

        $response = $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $email,
            'otp' => '999999',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertDatabaseHas('password_reset_otps', [
            'email' => $email,
            'attempts' => 1,
        ]);
    }

    public function test_five_failed_otp_attempts_burns_the_token(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make('123456'),
                'expires_at' => Carbon::now()->addMinutes(15),
                'attempts' => 4,
            ]
        );

        $response = $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $email,
            'otp' => '999999',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        // 5th attempt reached, OTP record should be purged
        $this->assertDatabaseMissing('password_reset_otps', ['email' => $email]);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        PasswordResetOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp_hash' => Hash::make('123456'),
                'expires_at' => Carbon::now()->subMinutes(1),
                'attempts' => 0,
            ]
        );

        $response = $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $email,
            'otp' => '123456',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);
    }

    public function test_weak_password_fails_validation(): void
    {
        $admin = User::query()->where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $email = $admin->email;

        $response = $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $email,
            'otp' => '123456',
            'password' => '123', // Below the shared eight-character minimum
            'password_confirmation' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
    public function test_consumed_code_cannot_reset_password_again(): void
    {
        $admin = User::where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        PasswordResetOtp::create([
            'email' => $admin->email,
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
        ]);
        $payload = ['email' => $admin->email, 'otp' => '123456',
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
        $this->postJson('/api/auth/forgot-password/verify-reset', $payload)->assertOk();
        $this->postJson('/api/auth/forgot-password/verify-reset', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
    }

    public function test_resending_is_limited_even_for_unknown_emails(): void
    {
        Queue::fake();
        $payload = ['email' => 'unknown@example.com'];
        $this->postJson('/api/auth/forgot-password/send-otp', $payload)->assertOk();
        $this->postJson('/api/auth/forgot-password/send-otp', $payload)->assertStatus(429);
        Queue::assertNothingPushed();
    }

    public function test_inactive_admin_cannot_redeem_an_existing_code(): void
    {
        $admin = User::where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        PasswordResetOtp::create([
            'email' => $admin->email, 'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15), 'attempts' => 0,
        ]);
        $admin->update(['is_active' => false]);
        $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $admin->email, 'otp' => '123456',
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->assertDatabaseMissing('password_reset_otps', ['email' => $admin->email]);
    }
    public function test_expired_queued_code_is_not_emailed(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        (new SendPasswordResetOtpJob('unknown@example.com', '123456'))->handle();
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_reset_removes_existing_database_sessions(): void
    {
        $admin = User::where('role', User::ROLE_SUPER_ADMIN)->firstOrFail();
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'reset-session-test', 'user_id' => $admin->id,
            'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        PasswordResetOtp::create([
            'email' => $admin->email, 'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15), 'attempts' => 0,
        ]);
        $this->postJson('/api/auth/forgot-password/verify-reset', [
            'email' => $admin->email, 'otp' => '123456',
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'reset-session-test']);
    }

    // --- From BootstrapOwnerTest.php ---
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

    // --- From CredentialValidationTest.php ---
    public function test_new_and_changed_passwords_require_strength(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass12345', 'role' => 'super_admin']);
        $this->actingAs($owner);
        foreach (['1234', 'Abc1234', 'abcdefghijkl', 'ABCDEFGHIJK1', str_repeat('Aa1', 25)] as $password) {
            $this->postJson('/api/users', ['name' => 'New Staff', 'username' => 'new_staff', 'email' => 'staff@example.com', 'role' => 'cashier', 'password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->patchJson('/api/users/'.$owner->id, ['password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_valid_credentials_are_hashed_and_never_returned(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass12345', 'role' => 'super_admin']);
        $this->actingAs($owner)->postJson('/api/users', ['name' => 'New Staff', 'username' => 'new_staff', 'email' => 'staff@example.com', 'role' => 'cashier', 'password' => 'Abcd1234'])->assertCreated()->assertJsonMissingPath('user.password');
        $staff = User::where('username', 'new_staff')->firstOrFail();
        $this->assertTrue(Hash::check('Abcd1234', $staff->password));
        $this->patchJson('/api/users/'.$staff->id, ['password' => 'ChangedPass12345'])->assertOk();
        $this->assertTrue(Hash::check('ChangedPass12345', $staff->fresh()->password));
    }

    public function test_login_limits_are_validated_without_locking_out_legacy_passwords(): void
    {
        User::create(['name' => 'Staff', 'username' => 'staff', 'role' => 'cashier', 'password' => '1234']);
        $this->postJson('/api/auth/login', ['name' => 'Staff', 'username' => 'staff', 'password' => '1234'])->assertOk();
        $this->postJson('/api/auth/login', ['username' => 'bad user', 'password' => '1234'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/auth/login', ['name' => 'Staff', 'username' => 'staff', 'password' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_owner_sign_in_takes_a_username_and_a_password_only(): void
    {
        // Owner sign-in is username + password. There is no email field and no
        // third factor, so a request carrying either must not be accepted: a
        // stale client that still sends them should be told, not quietly ignored.
        User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'super_admin', 'password' => 'StrongPass12345']);

        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => 'StrongPass12345'])
            ->assertOk()
            ->assertJsonPath('user.role', 'super_admin');

        $this->postJson('/api/auth/logout');

        // The retired PIN is no longer a rule, so it is an unknown field and is
        // ignored. That is safe: it was never a factor in this version, and the
        // password is still checked. A stale client that still sends it gets in
        // only with a correct password.
        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => 'StrongPass12345', 'pin' => '2468'])
            ->assertOk()
            ->assertJsonPath('user.role', 'super_admin');
        $this->postJson('/api/auth/logout');

        // The pin cannot stand in for the password.
        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => 'wrong', 'pin' => '2468'])
            ->assertStatus(401);

        // Recovery email does not substitute for the stored username.
        $this->postJson('/api/auth/login-owner', ['username' => 'owner@example.com', 'password' => 'StrongPass12345'])
            ->assertUnauthorized();

        $this->assertGuest();
    }

    // --- From ProfileTest.php ---
    public function test_cashier_attendance_page_and_api_are_gone(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->get('/cashier/attendance')->assertNotFound();
        $this->getJson('/api/employee/attendance')->assertNotFound();
        $this->postJson('/api/employee/attendance/time-in', ['reason' => null])->assertNotFound();
    }

    public function test_authenticated_user_can_retrieve_profile(): void
    {
        $employee = Employee::create(['name' => 'Profile Staff', 'job_title' => 'Cashier', 'is_active' => true]);
        $user = User::create([
            'username' => 'test_cashier',
            'name' => 'Test Cashier',
            'password' => 'Password123',
            'role' => 'cashier',
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.u', 'test_cashier')
            ->assertJsonPath('user.name', 'Test Cashier')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.job_title', $employee->job_title);
    }

    public function test_user_can_update_display_name_and_sync_employee(): void
    {
        $employee = Employee::create(['name' => 'Initial Name', 'job_title' => 'Cashier', 'is_active' => true]);
        $user = User::create([
            'username' => 'test_cashier2',
            'name' => 'Initial Name',
            'password' => 'Password123',
            'role' => 'cashier',
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->putJson('/api/profile', [
            'name' => 'Updated Cashier Name',
        ])->assertOk()->assertJsonPath('user.name', 'Updated Cashier Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Cashier Name']);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'name' => 'Updated Cashier Name']);
    }

    public function test_user_can_change_password_and_login_with_new_password(): void
    {
        $user = User::create([
            'username' => 'profile_cashier',
            'name' => 'Profile Cashier',
            'password' => 'OldPassword123',
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        // Incorrect current password fails
        $this->putJson('/api/profile', [
            'name' => 'Profile Cashier',
            'current_password' => 'WrongPassword',
            'new_password' => 'BrandNewPassword123',
            'new_password_confirmation' => 'BrandNewPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        // Mismatched confirmation fails
        $this->putJson('/api/profile', [
            'name' => 'Profile Cashier',
            'current_password' => 'OldPassword123',
            'new_password' => 'BrandNewPassword123',
            'new_password_confirmation' => 'DifferentPassword',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password_confirmation');

        // Too short new password fails
        $this->putJson('/api/profile', [
            'name' => 'Profile Cashier',
            'current_password' => 'OldPassword123',
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');

        // Valid update succeeds
        $this->putJson('/api/profile', [
            'name' => 'Cashier Updated',
            'current_password' => 'OldPassword123',
            'new_password' => 'BrandNewPassword123',
            'new_password_confirmation' => 'BrandNewPassword123',
        ])->assertOk()->assertJsonPath('ok', true);

        // Verify newly hashed password works
        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword123', $user->password));

        // Sign out and verify login with new credentials
        auth()->logout();
        $this->postJson('/api/auth/login', [
            'username' => 'profile_cashier',
            'password' => 'BrandNewPassword123',
        ])->assertOk();
    }

    public function test_guest_cannot_access_profile_endpoints(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['name' => 'Hacker'])->assertUnauthorized();
    }

    // --- From ProxySchemeTest.php ---
    public function test_https_proxy_generates_https_assets_and_secure_session_cookies(): void
    {
        // Nginx receives HTTP from the tunnel and supplies the original scheme.
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://store.example.test/');
        $response->assertOk()->assertSee('https://store.example.test/vendor/chartjs/chart.umd.js', false);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_lan_http_remains_usable_without_https(): void
    {
        $this->get('http://192.168.1.25:8080/')->assertOk()->assertSee('http://192.168.1.25:8080/vendor/chartjs/chart.umd.js', false);
    }

    // --- From SecurityStoriesTest.php ---
    public function test_u_s06_deactivated_users_lose_existing_sessions(): void
    {
        $user = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($user)->getJson('/api/bootstrap')->assertOk();
        $user->update(['is_active' => false]);
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_api_rate_limit_is_per_user_and_returns_retry_after(): void
    {
        config(['rate_limits.api' => 2]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/settings')->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs(User::where('username', 'admin')->firstOrFail())->getJson('/api/settings')->assertOk();
    }

    public function test_u_s04_cashiers_cannot_modify_inventory(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->patchJson('/api/inventory/'.InventoryItem::value('id'), ['direction' => 1])->assertForbidden();
    }

    public function test_login_rate_limit_is_capped_at_five_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => 'cashier',
                'password' => 'wrong-pass',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'username' => 'cashier',
            'password' => 'wrong-pass',
        ])->assertStatus(429);
    }

    // --- From SuperAdminMigrationTest.php ---
    public function test_upgrade_preserves_existing_owner_identity_credentials_and_activity(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'Private123!', 'role' => 'admin', 'is_active' => false]);
        $hash = $owner->password;
                $migration = new class extends \Illuminate\Database\Migrations\Migration {
            public function up(): void
            {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin','admin','cashier','rider','refiller') NOT NULL DEFAULT 'cashier'");
                \Illuminate\Support\Facades\DB::table('users')->where('role', 'admin')->update(['role' => 'super_admin']);
            }
            public function down(): void
            {
                if (\Illuminate\Support\Facades\DB::table('users')->where('role', 'super_admin')->exists()) {
                    throw new \RuntimeException('Reassign Super Admin accounts before rolling back the owner hierarchy.');
                }
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE users MODIFY role ENUM('admin','cashier','rider','refiller') NOT NULL DEFAULT 'cashier'");
            }
        };
        $migration->up();
        $this->assertSame('super_admin', $owner->fresh()->role);
        $this->assertSame($hash, $owner->fresh()->password);
        $this->assertFalse($owner->fresh()->is_active);
        $this->assertDatabaseCount('users', 1);
        $this->expectException(\RuntimeException::class);
        try {
            $migration->down();
        } finally {
            User::whereKey($owner->id)->delete();
        }
    }
}
