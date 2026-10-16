<?php

namespace Database\Seeders;

use App\Services\ForecastService;
use Illuminate\Database\Seeder;

class ForecastDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(ForecastService::class)->storeDemo();
    }
}
