<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds a realistic 30-day sales history having 15-40 jugs a day.
 */
class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->command) {
            $this->command->call('aquaflow:generate-history', [
                '--days' => 30,
                '--fresh' => true,
            ]);
        } else {
            Artisan::call('aquaflow:generate-history', [
                '--days' => 30,
                '--fresh' => true,
            ]);
        }
    }
}
