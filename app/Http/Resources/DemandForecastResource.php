<?php

namespace App\Http\Resources;

use App\Models\DemandForecast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin DemandForecast
 */
class DemandForecastResource extends JsonResource
{
    /** @return array<string, self> */
    public static function forSeries(Collection $forecasts): array
    {
        return $forecasts->map(fn (DemandForecast $forecast): self => new self($forecast))->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'series' => $this->series_name,
            'horizon' => $this->horizon_type,
            'date' => $this->forecast_date?->format('Y-m-d'),
            'history' => $this->historical_data ?? [],
            'forecast' => $this->forecasted_data ?? [],
            'model' => [
                'diagnostics' => $this->model_diagnostics ?? [],
                'history_warning' => count($this->historical_data ?? []) < 30 ? 'Insufficient history (have '.count($this->historical_data ?? []).' days, need 30)' : null,
                'order' => $this->model_order,
                'method' => $this->method,
                'differencing' => (int) $this->differencing,
                'aic' => (float) $this->aic_score,
                'mape' => (float) $this->mape_score,
                'mae' => (float) $this->mae_score,
                'rmse' => (float) $this->rmse_score,
                'adf_statistic' => (float) $this->adf_statistic,
                'adf_pvalue' => (float) $this->adf_pvalue,
                'ljung_box_pvalue' => (float) $this->ljung_box_pvalue,
                'residual_std' => (float) $this->residual_std,
            ],
        ];
    }
}
