<?php

namespace Tests\Feature;

use App\Models\Employee;
use Database\Seeders\EmployeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_employees_are_repeatable_without_overwriting_owner_changes(): void
    {
        $this->seed(EmployeeSeeder::class);
        $this->assertDatabaseCount('employees', 3);
        $this->assertSame(3, Employee::where('is_active', true)->count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employee_attendances', 0);

        $employee = Employee::where('name', 'Marco Reyes')->firstOrFail();
        $employee->update(['job_title' => 'Senior refill operator', 'is_active' => false]);
        Employee::where('name', 'Luis Garcia')->firstOrFail()->delete();
        $this->seed(EmployeeSeeder::class);

        $this->assertDatabaseCount('employees', 3);
        $this->assertSame('Senior refill operator', $employee->fresh()->job_title);
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertSoftDeleted('employees', ['name' => 'Luis Garcia']);
    }
}
