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
        config(['analytics.demo' => false]);
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

    public function test_u_s39_u_s41_forecasts_are_listed_per_series_with_diagnostics(): void
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
    public function test_presentation_refresh_uses_demo_history_without_running_sales_analytics(): void
    {
        config(['analytics.demo' => true]);
        \Illuminate\Support\Facades\Http::fake([
            '*/forecast' => \Illuminate\Support\Facades\Http::response([
                'forecast' => [25, 26, 32, 31, 24, 25, 24],
                'order' => 'ARIMA(0,0,1)',
                'mape' => 8.0,
            ]),
        ]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $sales = \App\Models\Transaction::count();

        $this->actingAs($this->owner)->postJson('/api/forecast/run')
            ->assertOk()
            ->assertJsonPath('forecasts.Refill Gallons.model.diagnostics.data_source', 'synthetic_demo');

        $original = DemandForecast::latestFor('Refill Gallons');
        $this->assertCount(30, $original->historical_data);

        $this->travel(1)->days();
        $this->actingAs($this->owner)->postJson('/api/forecast/run')
            ->assertOk()
            ->assertJsonCount(30, 'forecasts.Refill Gallons.history');
        $this->artisan('aquaflow:forecast')->assertSuccessful();
        $latest = DemandForecast::latestFor('Refill Gallons');
        $this->assertSame($original->historical_data, $latest->historical_data);
        $this->assertCount(7, $latest->forecasted_data);
        $this->assertTrue($original->fresh()->exists);
        $this->travelBack();
        $this->assertSame($sales, \App\Models\Transaction::count());
        $this->assertSame('synthetic_demo', DemandForecast::latestFor('Refill Gallons')->model_diagnostics['data_source']);
    }

    public function test_demo_seeder_preserves_synthetic_source_and_measured_metrics(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'forecast' => [25, 26, 32, 31, 24, 25, 24],
                'order' => 'ARIMA(0,0,1)',
                'method' => 'arima_seasonal',
                'mape' => 8.0,
                'model_diagnostics' => ['validation_available' => true, 'validation_days' => 7],
            ]),
        ]);
        $sales = \App\Models\Transaction::count();
        $this->seed(\Database\Seeders\ForecastDemoSeeder::class);
        $this->assertSame($sales, \App\Models\Transaction::count());
        $forecast = DemandForecast::latestFor('Refill Gallons');
        $this->assertSame(8.0, $forecast->mape_score);
        $this->assertCount(30, $forecast->historical_data);
        $this->assertSame('synthetic_demo', $forecast->model_diagnostics['data_source']);
        $this->assertSame(7, $forecast->model_diagnostics['validation_days']);
    }

}
