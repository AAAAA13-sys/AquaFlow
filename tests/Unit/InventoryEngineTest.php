<?php

namespace Tests\Unit;

use App\Services\InventoryEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pure math for the dynamic safety-stock / reorder-point engine.
 */
class InventoryEngineTest extends TestCase
{
    private InventoryEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new InventoryEngine;
    }

    public function test_safety_stock_scales_with_demand_variance_and_lead_time(): void
    {
        // Z=1.65, sigma=50, lead=4, priority=1.0 -> ceil(1.65*50*2) = 165
        $this->assertSame(165, $this->engine->safetyStock(50.0, 4, 1.0));

        // A longer lead time needs a bigger buffer.
        $this->assertGreaterThan(
            $this->engine->safetyStock(50.0, 2, 1.0),
            $this->engine->safetyStock(50.0, 8, 1.0)
        );

        // More volatile demand needs a bigger buffer.
        $this->assertGreaterThan(
            $this->engine->safetyStock(10.0, 4, 1.0),
            $this->engine->safetyStock(80.0, 4, 1.0)
        );
    }

    public function test_priority_factors_raise_the_buffer_for_critical_categories(): void
    {
        $base = $this->engine->safetyStock(50.0, 3, $this->engine->priorityFactor('Cleaning'));
        $critical = $this->engine->safetyStock(50.0, 3, $this->engine->priorityFactor('Filtration'));

        $this->assertGreaterThan($base, $critical);
        $this->assertSame(1.5, $this->engine->priorityFactor('Filtration'));
        $this->assertSame(1.25, $this->engine->priorityFactor('Consumable'));
        $this->assertSame(1.0, $this->engine->priorityFactor('Unknown'));
    }

    public function test_u_s44_reorder_point_is_demand_over_lead_time_plus_safety(): void
    {
        // 200/day x 3 days + 100 safety = 700
        $this->assertSame(700, $this->engine->reorderPoint(200.0, 3, 100));
    }

    public function test_target_stock_adds_the_review_period(): void
    {
        // 200/day x (3 + 7 review) + 100 = 2100
        $this->assertSame(2100, $this->engine->targetStock(200.0, 3, 100));
    }

    public function test_rounding_never_understates_a_threshold(): void
    {
        // 0.4/day x 3 days + 1 safety = 2.2 -> rounds up to 3
        $this->assertSame(3, $this->engine->reorderPoint(0.4, 3, 1));
    }

    public function test_lead_time_is_never_treated_as_zero(): void
    {
        $this->assertSame(50, $this->engine->reorderPoint(50.0, 0, 0));
    }
}
