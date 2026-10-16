<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass123', 'role' => 'super_admin']);
    }

    public function test_customer_requires_a_deliverable_mobile_number(): void
    {
        $this->actingAs($this->owner());
        foreach ([null, '', '-', 'abc', '021234567', '091234567890', ['09123456789']] as $contact) {
            $this->postJson('/api/customers', ['name' => 'Ana Santos', 'contact' => $contact])
                ->assertUnprocessable()->assertJsonValidationErrors('contact');
        }
        $this->postJson('/api/customers', ['name' => 'Ana Santos', 'contact' => '09123456789'])->assertCreated();
        $this->assertDatabaseHas('customers', ['name' => 'Ana Santos', 'contact' => '+639123456789']);
        $this->postJson('/api/customers', ['name' => 'Ana Santos', 'contact' => '+63 912-345-6789'])->assertCreated();
    }

    public function test_profile_name_boundary_does_not_partially_write(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->putJson('/api/profile', ['name' => str_repeat('A', 21)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Owner', $owner->fresh()->name);
        $this->putJson('/api/profile', ['name' => str_repeat('A', 20)])->assertOk();
    }

    public function test_new_cashier_must_replace_default_password_before_work(): void
    {
        $this->actingAs($this->owner());
        $id = $this->postJson('/api/users', ['name' => 'Ana Santos', 'username' => 'ana', 'role' => 'cashier', 'email' => 'ana@example.com'])
            ->assertCreated()->assertJsonPath('user.must_change_password', true)->json('user.id');
        $cashier = User::findOrFail($id);
        $this->assertTrue(Hash::check('12345678', $cashier->password));
        auth()->logout();
        $this->postJson('/api/auth/login', ['username' => 'ana', 'password' => '12345678'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/customers')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/profile')->assertOk();
        $this->postJson('/api/transactions', [])->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->putJson('/api/profile', ['name' => 'Ana Santos'])->assertUnprocessable()->assertJsonValidationErrors('new_password');
        $this->putJson('/api/profile', ['name' => 'Ana Santos', 'current_password' => '12345678', 'new_password' => 'ChangedPass123', 'new_password_confirmation' => 'ChangedPass123'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/customers')->assertOk();
        $this->assertTrue(Hash::check('ChangedPass123', $cashier->fresh()->password));
    }

    public function test_staff_name_limits_and_admin_password_validation(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/users', ['name' => str_repeat('A', 21), 'username' => 'new_staff', 'role' => 'cashier'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/users/'.$owner->id, ['name' => str_repeat('A', 21)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Owner', $owner->fresh()->name);
        $this->postJson('/api/users', ['name' => str_repeat('A', 20), 'username' => 'new_admin', 'role' => 'admin', 'email' => 'newadmin@example.com', 'password' => '12345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_unsupported_roles_cannot_be_created_or_signed_in(): void
    {
        $owner = $this->owner();
        foreach (['manager'] as $role) {
            $this->actingAs($owner)->postJson('/api/users', ['name' => 'Staff', 'username' => $role, 'role' => $role, 'password' => 'StrongPass123'])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
            if ($role === 'manager') {
                continue;
            }
            User::create(['name' => 'Staff', 'username' => $role, 'role' => $role, 'password' => 'StrongPass123']);
            auth()->logout();
            $this->postJson('/api/auth/login', ['username' => $role, 'password' => 'StrongPass123'])
                ->assertUnauthorized()->assertExactJson(['message' => 'Invalid username or password.']);
        }
        $this->actingAs($owner)->getJson('/api/users')->assertOk()->assertJsonCount(1, 'users');
    }
}
