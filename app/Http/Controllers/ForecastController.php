<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveForecastRequest;
use App\Http\Resources\DemandForecastResource;
use App\Http\Resources\InventoryItemResource;
use App\Models\DemandForecast;
use App\Models\InventoryItem;
use App\Services\AnalyticsClient;
use App\Services\ForecastService;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ForecastController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $inventory,
    ) {}

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
            'forecasts' => DemandForecastResource::forSeries(DemandForecast::latestPerSeries()),
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
            'forecasts' => DemandForecastResource::forSeries(DemandForecast::latestPerSeries()),
            'inventory' => InventoryItemResource::collection(
                InventoryItem::query()->with('supplier')->orderBy('id')->get()
            ),
            'advisories' => $this->inventory->advisories(),
        ]);
    }

    public function store(SaveForecastRequest $request): JsonResponse
    {
        $data = $request->validated();

        $forecast = app(ForecastService::class)->store($data);

        return response()->json(['forecast' => new DemandForecastResource($forecast)], 201);
    }
}
