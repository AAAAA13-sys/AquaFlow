<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminLoginTest extends TestCase
{
    use RefreshDatabase;

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
}
