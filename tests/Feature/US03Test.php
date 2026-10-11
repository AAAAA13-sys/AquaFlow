<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** US-03 superseded by the approved owner email verification workflow. */
class US03Test extends TestCase
{
    use RefreshDatabase;

    public function test_neither_correct_nor_incorrect_legacy_pin_grants_a_session(): void
    {
        $this->seed();
        foreach (['2468', '0000'] as $pin) {
            $this->postJson('/api/auth/login-owner', ['username' => 'admin', 'password' => '1234', 'pin' => $pin])->assertUnprocessable();
            $this->assertGuest();
        }
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_owner_login_has_its_own_email_screen(): void
    {
        $this->get('/owner/login')->assertOk()->assertSee('Email verification code')->assertDontSee('id="pin"', false);
    }
}
