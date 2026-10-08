<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Marco Reyes', 'job_title' => 'Refill operator'],
            ['name' => 'Luis Garcia', 'job_title' => 'Delivery worker'],
            ['name' => 'Carla Mendoza', 'job_title' => 'Store assistant'],
        ] as $employee) {
            // Preserve edited, deactivated and archived employee records on repeat runs.
            Employee::withTrashed()->firstOrCreate(
                ['name' => $employee['name']],
                $employee + ['is_active' => true],
            );
        }
    }
}
