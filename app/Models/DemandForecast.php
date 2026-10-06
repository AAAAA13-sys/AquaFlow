<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A stored ARIMA forecast produced by the Tier 3 Python service.
 */
class DemandForecast extends Model
{
    protected $fillable = [
        'model_diagnostics',
        'series_name',
        'horizon_type',
        'forecast_date',
        'historical_data',
        'forecasted_data',
        'model_order',
        'method',
        'differencing',
        'aic_score',
        'mape_score',
        'mae_score',
        'rmse_score',
        'adf_statistic',
        'adf_pvalue',
        'ljung_box_pvalue',
        'residual_std',
    ];

    protected function casts(): array
    {
        return [
            'historical_data' => 'array',
            'model_diagnostics' => 'array',
            'forecasted_data' => 'array',
            'forecast_date' => 'date:Y-m-d',
            'differencing' => 'integer',
            'aic_score' => 'float',
            'mape_score' => 'float',
            'mae_score' => 'float',
            'rmse_score' => 'float',
            'adf_statistic' => 'float',
            'adf_pvalue' => 'float',
            'ljung_box_pvalue' => 'float',
            'residual_std' => 'float',
        ];
    }

    /** The newest row for each series, keyed by series name. */
    public static function latestPerSeries(): Collection
    {
        $ids = static::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('series_name')
            ->pluck('id');

        return static::query()
            ->whereIn('id', $ids)
            ->orderBy('series_name')
            ->get()
            ->keyBy('series_name');
    }

    public static function latestFor(string $series): ?self
    {
        return static::query()
            ->where('series_name', $series)
            ->orderByDesc('forecast_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Mean of the projected series (used as the daily demand rate). */
    public function forecastAverage(): float
    {
        $values = $this->forecasted_data ?? [];
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /** Sample standard deviation of the projected series. */
    public function forecastStdDev(): float
    {
        $values = $this->forecasted_data ?? [];
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = $this->forecastAverage();
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ((float) $value - $mean) ** 2;
        }

        return sqrt($sum / ($count - 1));
    }
}
