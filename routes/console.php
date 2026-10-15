<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Refresh the ARIMA demand forecasts and recompute the dynamic safety stock /
| reorder points every night. Run the scheduler with:
|
|     php artisan schedule:work          (foreground)
|     php artisan schedule:run           (once, e.g. from cron / Task Scheduler)
|
*/

Schedule::command('aquaflow:forecast')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Refresh ARIMA forecasts and inventory thresholds');
