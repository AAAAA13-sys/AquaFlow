<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_cannot_access_cashier_data_and_attendance_routes_are_gone(): void
    {
        $this->seed();
        $employee = Employee::where('name', 'Carla Mendoza')->firstOrFail();
        $user = User::create(['username' => 'qa_rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => $employee->id, 'is_active' => true]);
        $this->actingAs($user);
        foreach (['bootstrap', 'customers', 'transactions', 'inventory', 'users'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden();
        }
        $this->get('/cashier')->assertForbidden();
        $this->get('/employee')->assertOk();

        // Attendance left the product, so the routes must not answer at all.
        $this->getJson('/api/attendance')->assertNotFound();
        $this->getJson('/api/employee/attendance')->assertNotFound();
        $this->postJson('/api/employee/attendance/time-in')->assertNotFound();
        $this->postJson('/api/attendance/employees/'.$employee->id.'/time-in')->assertNotFound();
        $this->get('/cashier/attendance')->assertNotFound();
    }
}
