<?php

namespace Tests\Feature;

use App\Models\DemandForecast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $this->owner = User::query()->where('username', 'admin')->firstOrFail();
    }

    private function storeForecast(string $series = 'Refill Gallons'): DemandForecast
    {
        return DemandForecast::query()->create([
            'series_name' => $series,
            'horizon_type' => 'Daily',
            'forecast_date' => today(),
            'historical_data' => array_fill(0, 30, 250),
            'forecasted_data' => [204.4, 219.1, 210.2, 212.3, 425.0, 416.8, 221.4],
            'model_order' => 'ARIMA(0,0,1)',
            'method' => 'arima_seasonal',
            'differencing' => 0,
            'aic_score' => 1033.44,
            'mape_score' => 10.15,
            'mae_score' => 28.56,
            'rmse_score' => 33.95,
            'adf_statistic' => -2.51,
            'adf_pvalue' => 0.0184,
            'ljung_box_pvalue' => 0.9796,
            'residual_std' => 40.12,
        ]);
    }

    public function test_forecasts_are_listed_per_series_with_diagnostics(): void
    {
        $this->storeForecast();

        $response = $this->actingAs($this->cashier)->getJson('/api/forecast');

        $response->assertOk()->assertJsonStructure([
            'forecasts' => ['Refill Gallons' => ['series', 'history', 'forecast', 'model' => ['order', 'method', 'mape', 'ljung_box_pvalue']]],
        ]);

        $this->assertCount(7, $response->json('forecasts.Refill Gallons.forecast'));
        $this->assertSame(10.15, $response->json('forecasts.Refill Gallons.model.mape'));
    }

    public function test_a_single_series_can_be_requested(): void
    {
        $this->storeForecast('Heat Shrink Seals');

        $this->actingAs($this->cashier)
            ->getJson('/api/forecast?series=Heat%20Shrink%20Seals')
            ->assertOk()
            ->assertJsonPath('forecast.series', 'Heat Shrink Seals');
    }

    public function test_an_unknown_series_returns_404(): void
    {
        $this->actingAs($this->cashier)
            ->getJson('/api/forecast?series=Nope')
            ->assertStatus(404);
    }

    public function test_only_the_owner_can_store_a_forecast(): void
    {
        $payload = [
            'series_name' => 'Refill Gallons',
            'historical' => [250, 260, 255],
            'forecasted' => [245, 250, 255],
            'model_order' => 'ARIMA(1,0,1)',
            'mape' => 9.5,
        ];

        $this->actingAs($this->cashier)->postJson('/api/forecast', $payload)->assertStatus(403);

        $this->actingAs($this->owner)
            ->postJson('/api/forecast', $payload)
            ->assertCreated()
            ->assertJsonPath('forecast.model.order', 'ARIMA(1,0,1)');
    }

    public function test_only_the_owner_can_trigger_a_forecast_run(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/forecast/run')->assertStatus(403);
    }

    public function test_a_forecast_run_reports_a_clear_error_when_the_service_is_offline(): void
    {
        // Point the client at a dead port so the failure path is exercised.
        config(['analytics.url' => 'http://127.0.0.1:1']);
        config(['analytics.connect_timeout' => 1]);

        $response = $this->actingAs($this->owner)->postJson('/api/forecast/run');

        $response->assertStatus(502);
        $this->assertStringContainsString('analytics', strtolower((string) $response->json('message')));
    }

    public function test_storing_a_forecast_requires_series_values(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/forecast', ['series_name' => 'Refill Gallons', 'historical' => [], 'forecasted' => []])
            ->assertStatus(422);
    }
}
