<?php

namespace Tests\Feature;

use App\Models\DemandForecast;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
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

        $this->seedForecasts();
    }

    /** The dynamic engine needs stored forecasts to derive demand from. */
    private function seedForecasts(): void
    {
        $series = [
            'Refill Gallons' => [200, 210, 220, 230, 400, 410, 220],
            'Non-Spill Caps' => [200, 210, 220, 230, 400, 410, 220],
            'Heat Shrink Seals' => [200, 210, 220, 230, 400, 410, 220],
            'Sediment Filters' => [0.1, 0.1, 0.1, 0.1, 0.2, 0.2, 0.1],
        ];

        foreach ($series as $name => $values) {
            DemandForecast::query()->create([
                'series_name' => $name,
                'horizon_type' => 'Daily',
                'forecast_date' => today(),
                'historical_data' => array_fill(0, 30, 250),
                'forecasted_data' => $values,
                'model_order' => 'ARIMA(0,0,1)',
                'method' => 'arima_seasonal',
                'differencing' => 0,
                'aic_score' => 1033.44,
                'mape_score' => 10.15,
                'mae_score' => 28.56,
                'rmse_score' => 33.95,
                'adf_statistic' => -2.5,
                'adf_pvalue' => 0.0184,
                'ljung_box_pvalue' => 0.9796,
                'residual_std' => 40.0,
            ]);
        }
    }

    private function item(string $name): InventoryItem
    {
        return InventoryItem::query()->where('item_name', $name)->firstOrFail();
    }

    public function test_inventory_list_exposes_thresholds_and_advisories(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/inventory');

        $response->assertOk()
            ->assertJsonCount(7, 'inventory')
            ->assertJsonStructure(['inventory' => [['id', 'item', 'on', 'ss', 'rop', 'target', 'lead', 'status']], 'advisories']);

        $first = $response->json('inventory.0');
        $this->assertIsInt($first['rop']);
        $this->assertIsInt($first['target']);
    }

    public function test_raising_the_lead_time_raises_safety_stock_and_rop(): void
    {
        $caps = $this->item('Non-Spill Caps');
        $ropBefore = $caps->reorder_point;
        $safetyBefore = $caps->safety_stock;

        $response = $this->actingAs($this->cashier)
            ->patchJson('/api/inventory/' . $caps->id, ['lead_time_days' => 5]);

        $response->assertOk();

        $caps->refresh();
        $this->assertSame(5, $caps->lead_time_days);
        $this->assertGreaterThan($ropBefore, $caps->reorder_point);
        $this->assertGreaterThan($safetyBefore, $caps->safety_stock);
        $this->assertNotNull($caps->recalculated_at);
    }

    public function test_lead_time_out_of_range_is_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->patchJson('/api/inventory/' . $this->item('Non-Spill Caps')->id, ['lead_time_days' => 99])
            ->assertStatus(422);
    }

    public function test_stock_can_be_adjusted_up_and_down(): void
    {
        $caps = $this->item('Non-Spill Caps');
        $before = $caps->stock_on_hand;

        $this->actingAs($this->cashier)
            ->patchJson('/api/inventory/' . $caps->id, ['direction' => 1])
            ->assertOk();

        $this->assertSame($before + 500, $caps->refresh()->stock_on_hand);

        $this->actingAs($this->cashier)
            ->patchJson('/api/inventory/' . $caps->id, ['direction' => -1])
            ->assertOk();

        $this->assertSame($before + 450, $caps->refresh()->stock_on_hand);
    }

    public function test_stock_never_goes_negative(): void
    {
        $item = $this->item('5-Micron Sediment Filter Cartridges');
        $item->update(['stock_on_hand' => 0]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->cashier)
                ->patchJson('/api/inventory/' . $item->id, ['direction' => -1])
                ->assertOk();
        }

        $this->assertSame(0, $item->refresh()->stock_on_hand);
    }

    public function test_only_the_owner_can_recalculate_every_item(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/inventory/recalculate')
            ->assertStatus(403);

        $response = $this->actingAs($this->owner)->postJson('/api/inventory/recalculate');

        $response->assertOk()->assertJsonPath('updated', 7);
    }

    public function test_advisories_endpoint_returns_a_draft_purchase_order(): void
    {
        // Force a shortage so there is something to advise on.
        $this->item('Non-Spill Caps')->update(['stock_on_hand' => 10]);

        $response = $this->actingAs($this->cashier)->getJson('/api/advisories');

        $response->assertOk()->assertJsonStructure(['generated_at', 'count', 'advisories', 'draft_po']);

        $advisory = collect($response->json('advisories'))->firstWhere('item', 'Non-Spill Caps');
        $this->assertNotNull($advisory);
        $this->assertSame('critical', $advisory['severity']);
        $this->assertGreaterThan(0, $advisory['order_quantity']);
        $this->assertSame('SealPack', $advisory['supplier']);
    }

    public function test_safety_stock_is_derived_from_forecast_variance(): void
    {
        $this->actingAs($this->owner)->postJson('/api/inventory/recalculate')->assertOk();

        $caps = $this->item('Non-Spill Caps');

        // stddev of [200,210,220,230,400,410,220] ~= 86.8, lead 2, priority 1.25
        // safety = ceil(1.65 * 86.8 * sqrt(2) * 1.25) ~= 254
        $this->assertGreaterThan(200, $caps->safety_stock);
        $this->assertLessThan(320, $caps->safety_stock);
        $this->assertGreaterThan($caps->safety_stock, $caps->reorder_point);
    }
}
