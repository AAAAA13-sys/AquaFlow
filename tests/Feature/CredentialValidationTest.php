<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CredentialValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_changed_passwords_require_strength(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass12345', 'role' => 'admin']);
        $this->actingAs($owner);
        foreach (['1234', 'Abc1234', 'abcdefghijkl', 'ABCDEFGHIJK1', str_repeat('Aa1', 25)] as $password) {
            $this->postJson('/api/users', ['name' => 'New Staff', 'username' => 'new_staff', 'role' => 'cashier', 'password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->patchJson('/api/users/'.$owner->id, ['password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_valid_credentials_are_hashed_and_never_returned(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass12345', 'role' => 'admin']);
        $this->actingAs($owner)->postJson('/api/users', ['name' => 'New Staff', 'username' => 'new_staff', 'role' => 'cashier', 'password' => 'Abcd1234'])->assertCreated()->assertJsonMissingPath('user.password');
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

    public function test_a_submitted_owner_pin_is_rejected_outright(): void
    {
        // The PIN is retired. Sending one is not merely ignored - the request is
        // refused, so a stale client cannot believe it authenticated with it.
        User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'password' => 'StrongPass12345', 'email' => 'owner@example.com']);
        $this->postJson('/api/auth/login-owner', ['email' => 'owner@example.com', 'password' => 'StrongPass12345', 'pin' => '2468'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pin');
    }
}
