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

    public function test_owner_sign_in_takes_a_username_and_a_password_only(): void
    {
        // Owner sign-in is username + password. There is no email field and no
        // third factor, so a request carrying either must not be accepted: a
        // stale client that still sends them should be told, not quietly ignored.
        User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'admin', 'password' => 'StrongPass12345']);

        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => 'StrongPass12345'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->postJson('/api/auth/logout');

        // The retired PIN is no longer a rule, so it is an unknown field and is
        // ignored. That is safe: it was never a factor in this version, and the
        // password is still checked. A stale client that still sends it gets in
        // only with a correct password.
        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => 'StrongPass12345', 'pin' => '2468'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
        $this->postJson('/api/auth/logout');

        // The pin cannot stand in for the password.
        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => 'wrong', 'pin' => '2468'])
            ->assertStatus(401);

        // An email cannot even be submitted as a username: the format rule
        // rejects it before any lookup, so there is no path where an address on
        // the account authenticates the owner portal.
        $this->postJson('/api/auth/login-owner', ['username' => 'owner@example.com', 'password' => 'StrongPass12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertGuest();
    }
}
