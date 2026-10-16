<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Demo dataset for AquaFlow.
 *
 *     php artisan migrate:fresh --seed
 *     php artisan aquaflow:generate-history     (30 days of sales for the model)
 *     php artisan aquaflow:forecast             (run ARIMA + inventory thresholds)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            EmployeeSeeder::class,
            UserSeeder::class,
            CatalogSeeder::class,
            CustomerSeeder::class,
            TransactionSeeder::class,
        ]);
    }
}
