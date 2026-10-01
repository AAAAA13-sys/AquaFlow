<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts. Passwords and the Owner PIN are bcrypt hashed, never stored
 * in plain text.
 *
 *   cashier / 1234
 *   admin   / 1234  + Owner PIN 2468
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
                'pin' => null,
                'is_active' => true,
            ]
        );

        User::query()->updateOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('1234'),
                'role' => User::ROLE_ADMIN,
                'name' => 'Yuri Soliven (Station Owner)',
                'pin' => Hash::make('2468'),
                'is_active' => true,
            ]
        );
    }
}
