<?php

namespace App\Console\Commands;

use App\Services\AnalyticsClient;
use App\Services\InventoryEngine;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Daily background job: refresh the ARIMA forecasts (Tier 3) and recompute the
 * dynamic Safety Stock / Reorder Points (Phase 4).
 *
 *     php artisan aquaflow:forecast
 *     php artisan aquaflow:forecast --skip-analytics
 *
 * Schedule it in routes/console.php or with the OS scheduler.
 */
class RefreshForecasts extends Command
{
    protected $signature = 'aquaflow:forecast
                            {--days=90 : History window in days}
                            {--horizon=7 : Forecast horizon in days}
                            {--skip-analytics : Only recompute inventory thresholds}';

    protected $description = 'Refresh ARIMA forecasts and dynamic inventory thresholds';

    public function handle(AnalyticsClient $analytics, InventoryEngine $engine): int
    {
        $days = max(30, (int) $this->option('days'));
        $horizon = max(1, (int) $this->option('horizon'));

        if (! $this->option('skip-analytics')) {
            $this->info("Running the ARIMA forecast ({$days}-day history, {$horizon}-day horizon)...");

            try {
                $summary = $analytics->run($days, $horizon);
            } catch (RuntimeException $exception) {
                $this->error('Forecast refresh failed: ' . $exception->getMessage());
                $this->warn('Recomputing inventory thresholds from the forecasts already stored.');

                $updated = $engine->recalculateAll();
                $this->info("Inventory items updated: {$updated}");

                return self::FAILURE;
            }

            foreach ($summary['series'] ?? [] as $series) {
                $this->line(sprintf(
                    '  %-20s %-16s MAPE %5s%%  Ljung-Box %s',
                    $series['series'] ?? '?',
                    $series['order'] ?? '?',
                    $series['mape'] ?? '?',
                    $series['ljung_box_pvalue'] ?? '?',
                ));
            }
        }

        $updated = $engine->recalculateAll();
        $this->info("Inventory items recalculated: {$updated}");

        $advisories = $engine->advisories();
        if ($advisories->isEmpty()) {
            $this->info('No items need reordering.');
        } else {
            $this->newLine();
            $this->warn('Restock advisories:');
            foreach ($advisories as $advisory) {
                $this->line(sprintf(
                    '  %-8s %-38s %5s days left  order %s %s from %s',
                    strtoupper($advisory['severity']),
                    $advisory['item'],
                    $advisory['days_left'],
                    number_format($advisory['order_quantity']),
                    $advisory['unit'],
                    $advisory['supplier'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
