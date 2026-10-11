<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_cannot_access_cashier_data_and_can_only_punch_self(): void
    {
        $this->seed();
        $employee = Employee::firstOrFail();
        $user = User::create(['username' => 'rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => $employee->id, 'is_active' => true]);
        $this->actingAs($user);
        foreach (['bootstrap', 'customers', 'transactions', 'inventory', 'users', 'attendance'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden();
        }
        $this->get('/cashier')->assertForbidden();
        $this->get('/employee')->assertOk();
        $this->travelTo(now()->startOfDay()->addHours(7));
        $this->postJson('/api/employee/attendance/time-in')->assertOk();
        $this->assertDatabaseHas('employee_attendances', ['employee_id' => $employee->id, 'time_in_by' => $user->id]);
        $this->postJson('/api/attendance/employees/'.$employee->id.'/time-in')->assertForbidden();
        $this->postJson('/api/employee/attendance/time-out')->assertUnprocessable();
    }
}
