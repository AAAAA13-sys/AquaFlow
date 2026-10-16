<?php

namespace Tests\Unit;

use Database\Seeders\TransactionSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TransactionSeederTest extends TestCase
{
    public function test_seeder_generates_thirty_days_of_demo_history(): void
    {
        Artisan::shouldReceive('call')->once()->with('aquaflow:generate-history', [
            '--days' => 30,
            '--fresh' => true,
        ])->andReturn(0);

        (new TransactionSeeder)->run();
    }
}
