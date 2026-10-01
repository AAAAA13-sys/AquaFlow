<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveForecastRequest;
use App\Http\Resources\DemandForecastResource;
use App\Http\Resources\InventoryItemResource;
use App\Models\DemandForecast;
use App\Models\InventoryItem;
use App\Services\AnalyticsClient;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ForecastController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $inventory,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $series = $request->string('series')->toString();

        if ($series !== '') {
            $forecast = DemandForecast::latestFor($series);

            if ($forecast === null) {
                return response()->json(['message' => 'No stored forecast for that series.'], 404);
            }

            return response()->json(['forecast' => new DemandForecastResource($forecast)]);
        }

        return response()->json([
            'forecasts' => DemandForecast::latestPerSeries()
                ->map(fn (DemandForecast $forecast): DemandForecastResource => new DemandForecastResource($forecast))
                ->all(),
        ]);
    }

    public function run(AnalyticsClient $analytics): JsonResponse
    {
        try {
            $summary = $analytics->run(
                (int) config('analytics.history_days'),
                (int) config('analytics.horizon_days'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $updated = $this->inventory->recalculateAll();

        return response()->json([
            'summary' => $summary,
            'inventory_updated' => $updated,
            'forecasts' => DemandForecast::latestPerSeries()
                ->map(fn (DemandForecast $forecast): DemandForecastResource => new DemandForecastResource($forecast))
                ->all(),
            'inventory' => InventoryItemResource::collection(
                InventoryItem::query()->with('supplier')->orderBy('id')->get()
            ),
            'advisories' => $this->inventory->advisories(),
        ]);
    }

    public function store(SaveForecastRequest $request): JsonResponse
    {
        $data = $request->validated();

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

        return response()->json(['forecast' => new DemandForecastResource($forecast)], 201);
    }
}
