<?php
namespace Tests\Feature;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\User;
use App\Models\PasswordResetOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_require_email_and_admin_requires_a_strong_password(): void
    {
        $this->seed();
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
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
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->patchJson('/api/users/'.$cashier->id, ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson('/api/users/'.$cashier->id, ['email' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('cashier', $cashier->fresh()->role);
    }
}
