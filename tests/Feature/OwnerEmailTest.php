<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerCode;
use App\Models\User;
use App\Services\OwnerEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OwnerEmailTest extends TestCase
{
    use RefreshDatabase;

    private function seedLoginUsers(): void
    {
        User::create(['name' => 'Owner', 'password' => 'Owner123', 'username' => 'admin', 'role' => 'admin', 'email' => null, 'email_verified_at' => null, 'is_active' => true, 'is_bootstrap' => false]);
        User::create(['name' => 'Cashier', 'password' => 'Cashier123', 'username' => 'cashier', 'role' => 'cashier', 'is_active' => true]);
    }


    public function test_fixed_code_cannot_log_in_without_a_password_challenge(): void
    {
        $this->seedLoginUsers();
        $this->postJson('/api/auth/verify-email', ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_password_only_creates_pending_challenge_and_pin_cannot_bypass_email(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com', 'password' => 'Owner123']);
        $this->postJson('/api/auth/login-owner', ['email' => $owner->email, 'password' => 'Owner123'])
            ->assertOk()->assertJsonPath('pending', true);
        $this->assertGuest();
        $this->postJson('/api/auth/login-owner', ['email' => $owner->email, 'password' => 'Owner123', 'pin' => '1234'])->assertUnprocessable();
    }

    public function test_employee_login_rejects_even_an_email_enrolled_owner(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com', 'password' => 'Owner123']);
        $this->postJson('/api/auth/login', ['username' => $owner->username, 'password' => 'Owner123'])->assertForbidden();
        $this->assertGuest();
        Queue::assertNothingPushed();
    }

    public function test_admin_login_rejects_staff_and_requires_otp_before_access(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->postJson('/api/auth/login-owner', ['email' => 'staff@example.com', 'password' => '1234'])->assertUnauthorized();
        Queue::assertNothingPushed();
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com', 'password' => 'Owner123']);
        $this->postJson('/api/auth/login-owner', ['email' => $owner->email, 'password' => 'Owner123'])->assertOk()->assertJsonPath('pending', true);
        $this->assertGuest();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->getJson('/api/bootstrap')->assertUnauthorized();
        $this->postJson('/api/auth/login-owner', ['email' => $owner->email, 'password' => 'Owner123'])->assertUnprocessable();
        $this->withCookie(config('session.cookie'), session()->getId());
        $job = Queue::pushed(SendOwnerCode::class)->first();
        $this->postJson('/api/auth/verify-email', ['code' => $job->code])->assertOk()->assertJsonPath('user.role', 'admin');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_admin_email_is_required_and_unknown_email_or_wrong_password_sends_nothing(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com', 'password' => 'Owner123']);
        $this->postJson('/api/auth/login-owner', ['username' => $owner->username, 'password' => 'Owner123'])->assertUnprocessable();
        foreach ([['email' => 'unknown@example.com', 'password' => 'Owner123'], ['email' => $owner->email, 'password' => 'Wrong123']] as $credentials) {
            $this->postJson('/api/auth/login-owner', $credentials)->assertUnauthorized();
        }
        Queue::assertNothingPushed();
        $this->assertGuest();
    }

    public function test_email_login_uses_registered_recipient_and_recovery_uses_email(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com', 'password' => 'Owner123']);
        $this->postJson('/api/auth/login-owner', ['email' => 'OWNER@EXAMPLE.COM', 'password' => 'Owner123'])->assertOk()->assertJsonPath('pending', true);
        $this->assertDatabaseHas('owner_login_challenges', ['user_id' => $owner->id, 'email' => 'owner@example.com']);
        $codes = app(OwnerEmailService::class)->enroll($owner);
        $this->postJson('/api/auth/recover', ['email' => $owner->email, 'password' => 'Owner123', 'code' => $codes[0]])->assertOk();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_code_is_bound_to_session_expires_and_can_only_be_used_once(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com']);
        $service = app(OwnerEmailService::class);
        $id = $service->issue($owner, 'session-one');
        $job = Queue::pushed(SendOwnerCode::class)->first();
        $this->assertNull($service->verify($id, 'session-two', $job->code));
        $this->assertSame($owner->id, $service->verify($id, 'session-one', $job->code)?->id);
        $this->assertNull($service->verify($id, 'session-one', $job->code));
        $this->travel(61)->seconds();
        $id = $service->issue($owner, 'session-one');
        $job = Queue::pushed(SendOwnerCode::class)->last();
        $this->travel(6)->minutes();
        $this->assertNull($service->verify($id, 'session-one', $job->code));
    }

    public function test_attempt_limit_recovery_reuse_and_stale_job_delivery(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com']);
        $service = app(OwnerEmailService::class);
        $id = $service->issue($owner, 'session');
        $job = Queue::pushed(SendOwnerCode::class)->first();
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($service->verify($id, 'session', '000000'));
        }
        $this->assertNull($service->verify($id, 'session', $job->code));
        $this->travel(61)->seconds();
        $service->issue($owner, 'session');
        Mail::fake();
        $job->handle();
        Mail::assertNothingSent();
        $codes = $service->enroll($owner);
        $this->assertCount(8, $codes);
        $this->assertSame($owner->id, $service->recovery($owner, $codes[0])?->id);
        $this->assertNull($service->recovery($owner, $codes[0]));
    }

    public function test_log_transport_cannot_receive_verification_secrets(): void
    {
        $this->seedLoginUsers();
        config(['mail.default' => 'log']);
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com']);
        $this->expectException(ValidationException::class);
        app(OwnerEmailService::class)->issue($owner, 'session');
    }

    public function test_changing_password_invalidates_an_unconsumed_challenge(): void
    {
        $this->seedLoginUsers();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $owner = User::where('role', 'admin')->firstOrFail();
        $owner->update(['email' => 'owner@example.com']);
        $service = app(OwnerEmailService::class);
        $id = $service->issue($owner, 'session');
        $job = Queue::pushed(SendOwnerCode::class)->first();
        $owner->update(['password' => 'ChangedPassword123']);
        $this->assertNull($service->verify($id, 'session', $job->code));
    }

    public function test_upgrade_prompts_only_for_missing_owner_destinations(): void
    {
        $this->seedLoginUsers();
        $this->artisan('aquaflow:enroll-owner-email')
            ->expectsQuestion('Private verification email for admin', 'owner@example.com')
            ->assertExitCode(0);
        $owner = User::where('username', 'admin')->firstOrFail();
        $this->assertSame('owner@example.com', $owner->email);
        $this->assertNull($owner->email_verified_at);
        $this->artisan('aquaflow:enroll-owner-email')->assertExitCode(0);
        $this->assertSame('owner@example.com', $owner->fresh()->email);
    }
}
