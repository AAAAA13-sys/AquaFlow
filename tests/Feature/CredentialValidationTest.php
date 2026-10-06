<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CredentialValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_changed_passwords_require_strength_and_pin_is_numeric(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass12345', 'role' => 'admin']);
        $this->actingAs($owner);
        foreach (['1234', 'Abc1234', 'abcdefghijkl', 'ABCDEFGHIJK1', str_repeat('Aa1', 25)] as $password) {
            $this->postJson('/api/users', ['name' => 'New Staff', 'username' => 'new_staff', 'role' => 'cashier', 'password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->patchJson('/api/users/'.$owner->id, ['password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/users', ['name' => 'New Owner', 'username' => 'new_owner', 'role' => 'admin', 'password' => 'StrongPass12345', 'pin' => 'abcd'])->assertUnprocessable()->assertJsonValidationErrors('pin');
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

    public function test_login_limits_and_pin_format_are_validated_without_locking_out_legacy_passwords(): void
    {
        User::create(['name' => 'Staff', 'username' => 'staff', 'role' => 'cashier', 'password' => '1234']);
        $this->postJson('/api/auth/login', ['name' => 'Staff', 'username' => 'staff', 'password' => '1234'])->assertOk();
        $this->postJson('/api/auth/login', ['username' => 'bad user', 'password' => '1234'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/auth/login', ['name' => 'Staff', 'username' => 'staff', 'password' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/login-owner', ['username' => 'owner', 'password' => '1234', 'pin' => 'abcd'])->assertUnprocessable()->assertJsonValidationErrors('pin');
    }

    public function test_owner_pin_cannot_be_removed_without_changing_role(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass12345', 'role' => 'admin', 'pin' => Hash::make('2468')]);
        $this->actingAs($owner)->patchJson('/api/users/'.$owner->id, ['pin' => null])->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->assertTrue(Hash::check('2468', $owner->fresh()->pin));
    }
}
