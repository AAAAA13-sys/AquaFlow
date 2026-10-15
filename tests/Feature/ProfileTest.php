<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_cashier_attendance_page_and_api_are_gone(): void
    {
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier);

        $this->get('/cashier/attendance')->assertNotFound();
        $this->getJson('/api/employee/attendance')->assertNotFound();
        $this->postJson('/api/employee/attendance/time-in', ['reason' => null])->assertNotFound();
    }

    public function test_authenticated_user_can_retrieve_profile(): void
    {
        $employee = Employee::create(['name' => 'Profile Staff', 'job_title' => 'Cashier', 'is_active' => true]);
        $user = User::create([
            'username' => 'test_cashier',
            'name' => 'Test Cashier',
            'password' => 'Password123',
            'role' => 'cashier',
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.u', 'test_cashier')
            ->assertJsonPath('user.name', 'Test Cashier')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.job_title', $employee->job_title);
    }

    public function test_user_can_update_display_name_and_sync_employee(): void
    {
        $employee = Employee::create(['name' => 'Initial Name', 'job_title' => 'Cashier', 'is_active' => true]);
        $user = User::create([
            'username' => 'test_cashier2',
            'name' => 'Initial Name',
            'password' => 'Password123',
            'role' => 'cashier',
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        $this->putJson('/api/profile', [
            'name' => 'Updated Cashier Name',
        ])->assertOk()->assertJsonPath('user.name', 'Updated Cashier Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Cashier Name']);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'name' => 'Updated Cashier Name']);
    }

    public function test_user_can_change_password_and_login_with_new_password(): void
    {
        $user = User::create([
            'username' => 'profile_cashier',
            'name' => 'Profile Cashier',
            'password' => 'OldPassword123',
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        // Incorrect current password fails
        $this->putJson('/api/profile', [
            'name' => 'Profile Cashier',
            'current_password' => 'WrongPassword',
            'new_password' => 'BrandNewPassword123',
            'new_password_confirmation' => 'BrandNewPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        // Mismatched confirmation fails
        $this->putJson('/api/profile', [
            'name' => 'Profile Cashier',
            'current_password' => 'OldPassword123',
            'new_password' => 'BrandNewPassword123',
            'new_password_confirmation' => 'DifferentPassword',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password_confirmation');

        // Too short new password fails
        $this->putJson('/api/profile', [
            'name' => 'Profile Cashier',
            'current_password' => 'OldPassword123',
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');

        // Valid update succeeds
        $this->putJson('/api/profile', [
            'name' => 'Cashier Updated',
            'current_password' => 'OldPassword123',
            'new_password' => 'BrandNewPassword123',
            'new_password_confirmation' => 'BrandNewPassword123',
        ])->assertOk()->assertJsonPath('ok', true);

        // Verify newly hashed password works
        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword123', $user->password));

        // Sign out and verify login with new credentials
        auth()->logout();
        $this->postJson('/api/auth/login', [
            'username' => 'profile_cashier',
            'password' => 'BrandNewPassword123',
        ])->assertOk();
    }

    public function test_guest_cannot_access_profile_endpoints(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', ['name' => 'Hacker'])->assertUnauthorized();
    }
}
