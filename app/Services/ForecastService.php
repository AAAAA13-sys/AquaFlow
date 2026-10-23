<?php

namespace App\Services;

use App\Models\DemandForecast;
use Illuminate\Support\Facades\DB;

class ForecastService
{
    public function storeDemo(): DemandForecast
    {
        // Synthetic weekly pattern tailored for an approximately 8% holdout MAPE demo.
        $history = [24, 20, 21, 27, 25, 32, 30, 20, 25, 21, 27, 28, 35, 29, 23,
            23, 21, 22, 23, 32, 30, 23, 24, 21, 26, 24, 36, 34, 24, 30];
        $result = app(AnalyticsClient::class)->forecast($history, 7, today()->dayOfWeekIso - 1);
        $result['model_diagnostics']['data_source'] = 'synthetic_demo';
        return $this->store($result + [
            'series_name' => 'Refill Jugs',
            'historical' => $history,
            'forecasted' => $result['forecast'],
            'model_order' => $result['order'],
        ]);
    }

    public function store(array $data): DemandForecast
    {
        return DB::transaction(function () use ($data): DemandForecast {
            $forecast = DemandForecast::query()->create([
                'series_name' => $data['series_name'],
                'model_diagnostics' => $data['model_diagnostics'] ?? [],
                'horizon_type' => $data['horizon_type'] ?? 'Daily',
                'forecast_date' => today(),
                'historical_data' => array_map('floatval', $data['historical']),
                'forecasted_data' => array_map('floatval', $data['forecasted']),
                'model_order' => $data['model_order'] ?? 'ARIMA(1,1,1)',
                'method' => $data['method'] ?? 'arima',
                'differencing' => $data['differencing'] ?? 0,
                'aic_score' => $data['aic'] ?? 0,
                'mape_score' => $data['mape'] ?? 0,
                'mae_score' => $data['mae'] ?? 0,
                'rmse_score' => $data['rmse'] ?? 0,
                'adf_statistic' => $data['adf_statistic'] ?? 0,
                'adf_pvalue' => $data['adf_pvalue'] ?? 1,
                'ljung_box_pvalue' => $data['ljung_box_pvalue'] ?? 1,
                'residual_std' => $data['residual_std'] ?? 0,
            ]);

            app(InventoryEngine::class)->recalculateAll();

            return $forecast;
        });
    }
}
