<?php

namespace App\Services;

use App\Models\DemandForecast;
use Illuminate\Support\Facades\DB;

class ForecastService
{
    public function store(array $data): DemandForecast
    {
        return DB::transaction(function () use ($data): DemandForecast {
            $forecast = DemandForecast::query()->create([
                'series_name' => $data['series_name'],
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
