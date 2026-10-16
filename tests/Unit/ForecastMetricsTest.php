<?php

namespace Tests\Unit;

use App\Http\Resources\DemandForecastResource;
use App\Models\DemandForecast;
use Illuminate\Http\Request;
use Tests\TestCase;

class ForecastMetricsTest extends TestCase
{
    public function test_unvalidated_zero_is_not_perfect_accuracy(): void
    {
        $forecast = new DemandForecast(['method' => 'arima', 'mape_score' => 0]);
        $this->assertNull((new DemandForecastResource($forecast))->resolve(new Request)['model']['mape']);
        $forecast->model_diagnostics = ['validation_available' => true];
        $this->assertSame(0.0, (new DemandForecastResource($forecast))->resolve(new Request)['model']['mape']);
        $forecast->model_diagnostics = ['validation_available' => false];
        $forecast->mape_score = 8.5;
        $this->assertNull((new DemandForecastResource($forecast))->resolve(new Request)['model']['mape']);
    }
}
