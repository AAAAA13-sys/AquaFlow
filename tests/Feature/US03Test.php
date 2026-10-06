<?php

namespace Tests\Feature;

use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * US-03: a valid owner password paired with a wrong PIN must be rejected with
 * an inline "Invalid Owner PIN" banner, no session, and a logged attempt.
 */
class US03Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * @return array<string,string>
     */
    private function validPasswordWrongPin(): array
    {
        return [
            'username' => 'admin',
            'password' => '1234',
            'pin' => '0000',
        ];
    }

    public function test_a_valid_password_with_a_wrong_pin_is_rejected(): void
    {
        $this->postJson('/api/auth/login-owner', $this->validPasswordWrongPin())
            ->assertStatus(401);
    }

    public function test_the_rejection_uses_the_exact_invalid_owner_pin_message(): void
    {
        $response = $this->postJson('/api/auth/login-owner', $this->validPasswordWrongPin());

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid Owner PIN');

        // The banner is filled from this field, so the string must be exact.
        $this->assertSame('Invalid Owner PIN', AuthService::INVALID_OWNER_PIN);
    }

    public function test_a_wrong_pin_never_creates_a_session(): void
    {
        $this->postJson('/api/auth/login-owner', $this->validPasswordWrongPin())
            ->assertStatus(401);

        $this->assertGuest();

        // And the managerial screens stay shut.
        $this->getJson('/api/bootstrap')->assertStatus(401);
        $this->get('/admin/dashboard')->assertRedirect('/owner/login');
    }

    public function test_a_wrong_pin_is_logged_without_the_secret(): void
    {
        Log::spy();

        $this->postJson('/api/auth/login-owner', $this->validPasswordWrongPin())
            ->assertStatus(401);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []): bool {
                $this->assertStringContainsString('Owner PIN', $message);
                $this->assertSame('admin', $context['username'] ?? null);
                $this->assertNotEmpty($context['ip'] ?? null);

                // Never write a credential to the log.
                $flat = json_encode($context);
                $this->assertStringNotContainsString('1234', (string) $flat);
                $this->assertStringNotContainsString('0000', (string) $flat);

                return true;
            });
    }

    public function test_a_wrong_password_is_logged_separately_from_a_wrong_pin(): void
    {
        Log::spy();

        $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => 'not-the-password',
            'pin' => '2468',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Invalid owner username or password.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool =>
                ! str_contains($message, 'Owner PIN')
                && ($context['username'] ?? null) === 'admin'
            );
    }

    public function test_the_login_page_renders_the_error_banner(): void
    {
        // The banner element that displays the API message must be present.
        $this->get('/owner/login')
            ->assertOk()
            ->assertSee('id="err"', false)
            ->assertSee('auth-error', false)
            ->assertSee('doOwnerLogin()', false);
    }

    public function test_the_correct_pin_still_signs_the_owner_in(): void
    {
        // Regression guard: US-03 must not lock the legitimate owner out.
        $this->postJson('/api/auth/login-owner', [
            'username' => 'admin',
            'password' => '1234',
            'pin' => '2468',
        ])->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->assertAuthenticated();
    }
}
