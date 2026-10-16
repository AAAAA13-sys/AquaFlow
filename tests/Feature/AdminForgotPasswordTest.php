<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

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
}
