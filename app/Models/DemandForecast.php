<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A stored ARIMA forecast produced by the Tier 3 Python service.
 */
class DemandForecast extends Model
{
    /**
     * Fallback when config/analytics.php is unavailable (for example in a
     * unit test). Two days tolerates one missed nightly run.
     */
    public const DEFAULT_MAX_AGE_DAYS = 2;

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

    /** Configured age after which a forecast must not drive planning. */
    public function maxAgeDays(): int
    {
        return max(1, (int) config('analytics.forecast_max_age_days', self::DEFAULT_MAX_AGE_DAYS));
    }

    /** Whole days since the forecast was produced. Missing dates are stale. */
    public function ageInDays(): int
    {
        if ($this->forecast_date === null) {
            return PHP_INT_MAX;
        }

        return max(0, (int) $this->forecast_date->startOfDay()->diffInDays(today()->startOfDay(), false));
    }

    /**
     * True when the nightly job has not refreshed this series recently enough
     * for its thresholds to be trusted.
     */
    public function isStale(?int $maxAgeDays = null): bool
    {
        return $this->ageInDays() > ($maxAgeDays ?? $this->maxAgeDays());
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
