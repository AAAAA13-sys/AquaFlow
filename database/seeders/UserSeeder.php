<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for local development.
 *
 *   cashier  / 1234
 *   admin    / 1234   (owner sign-in is email + password + emailed code)
 *
 * These are development fixtures only. A distributed store is created through
 * Setup, which installs no sample accounts and generates its own one-time setup
 * code - this seeder must never run against store data.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['username' => 'cashier'],
            [
                'password' => Hash::make('1234'),
                'role' => User::ROLE_CASHIER,
                'name' => 'Juan Dela Cruz (Cashier #01)',
                'is_active' => true,
            ]
        );

        User::query()->updateOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'kmikaelim11@gmail.com',
                'password' => Hash::make('1234'),
                'role' => User::ROLE_ADMIN,
                'name' => 'Admin (kmikaelim11)',
                'is_active' => true,
                'is_bootstrap' => false,
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['username' => 'refiller'],
            [
                'password' => Hash::make('1234'),
                'role' => 'refiller',
                'name' => 'Marco Reyes (Refiller)',
                'employee_id' => \App\Models\Employee::where('name', 'Marco Reyes')->firstOrFail()->id,
                'is_active' => true,
            ]
        );

        User::query()->updateOrCreate(
            ['username' => 'rider'],
            [
                'password' => Hash::make('1234'),
                'role' => 'rider',
                'name' => 'Luis Garcia (Rider)',
                'employee_id' => \App\Models\Employee::where('name', 'Luis Garcia')->firstOrFail()->id,
                'is_active' => true,
            ]
        );
    }
}
