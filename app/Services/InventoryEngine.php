<?php

namespace App\Services;

use App\Models\DemandForecast;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryEngine
{
    // Service Constants & Fallback Rates
    public const SERVICE_LEVEL_Z = 1.65;
    public const DEFAULT_REVIEW_PERIOD_DAYS = 7;
    public const FALLBACK_DAILY_DEMAND = 285.0;
    public const ASSUMED_DEMAND_CV = 0.3;
    public const FILTER_GALLON_RATING = 2000;

    private const FALLBACK_DAILY_RATES = [
        'Soap' => 1.2,
        'Sponges' => 1.5,
        'Slim Jugs' => 1.8,
        'Round Jugs' => 1.4,
    ];

    // Demand Mapping & Profiles
    public function demandSeries(string $item): ?string
    {
        return match (true) {
            str_contains($item, 'Caps') => 'Non-Spill Caps',
            str_contains($item, 'Seals') => 'Heat Shrink Seals',
            str_contains($item, 'Filter') => 'Sediment Filters',
            default => null,
        };
    }

    /**
     * @return array{daily: float, stddev: float, source: string}
     */
    public function demandProfile(string $item): array
    {
        $series = $this->demandSeries($item);
        if ($series !== null) {
            $forecast = DemandForecast::latestFor($series);
            if ($forecast !== null) {
                $daily = $forecast->forecastAverage();
                $stdDev = $forecast->forecastStdDev();
                if ($stdDev <= 0.0) {
                    $stdDev = $daily * self::ASSUMED_DEMAND_CV;
                }

                return ['daily' => $daily, 'stddev' => $stdDev, 'source' => $series];
            }
        }

        foreach (self::FALLBACK_DAILY_RATES as $needle => $rate) {
            if (str_contains($item, $needle)) {
                return [
                    'daily' => (float) $rate,
                    'stddev' => $rate * self::ASSUMED_DEMAND_CV,
                    'source' => 'assumed rate',
                ];
            }
        }

        return [
            'daily' => self::FALLBACK_DAILY_DEMAND,
            'stddev' => self::FALLBACK_DAILY_DEMAND * self::ASSUMED_DEMAND_CV,
            'source' => 'default',
        ];
    }

    public function priorityFactor(string $category): float
    {
        return match ($category) {
            'Filtration' => 1.5,
            'Consumable' => 1.25,
            'Asset' => 1.2,
            'Cleaning' => 1.1,
            default => 1.0,
        };
    }

    // ---------------------------------------------------------------------
    // Canonical days-of-cover (SINGLE SOURCE OF TRUTH)
    //
    // Days Remaining = Current Stock Quantity / ARIMA Projected Daily Consumption
    //
    // This previously existed twice: once here and once in the browser
    // (insights.js dailyUse(), which multiplied forecast average by hard-coded
    // factors of 0.8/1.2/1.5). That produced two different answers on the same
    // screen - "Non-Spill Caps" read 2.2 days in the Days Left panel and 1.7 in
    // the advisory. Every consumer now reads these numbers instead of computing
    // its own.
    // ---------------------------------------------------------------------

    /**
     * @return array{daily_demand: float, demand_source: string, days_left: float|null, days_exact: float|null}
     */
    public function daysRemaining(InventoryItem|int $item): array
    {
        $model = $item instanceof InventoryItem
            ? $item
            : InventoryItem::query()->findOrFail($item);

        $profile = $this->demandProfile($model->item_name);
        $daily = (float) $profile['daily'];

        // Demand of zero is unknown demand, not infinite stock. Report it as null
        // so the UI can say "no forecast yet" instead of dividing by zero.
        $exact = $daily > 0.0 ? $model->stock_on_hand / $daily : null;

        return [
            'daily_demand' => round($daily, 2),
            'demand_source' => (string) $profile['source'],
            'days_exact' => $exact,
            'days_left' => $exact === null ? null : round($exact, 1),
        ];
    }

    /**
     * Same figure for many items, without N+1 queries.
     *
     * @param  iterable<int, InventoryItem>  $items
     * @return array<int, array{daily_demand: float, demand_source: string, days_left: float|null, days_exact: float|null}>
     */
    public function daysRemainingFor(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item->id] = $this->daysRemaining($item);
        }

        return $out;
    }

    /**
     * "1 unit" / "2 units" / "1 pack" - keeps advisory copy grammatical.
     */
    public function quantityLabel(int $quantity, string $unit): string
    {
        $quantity = max(0, $quantity);
        $noun = match (true) {
            $quantity === 1 && $unit === 'pcs' => 'unit',
            $quantity === 1 => rtrim($unit, 's'),
            default => str_ends_with($unit, 's') ? $unit : $unit . 's',
        };

        return $quantity . ' ' . $noun;
    }

    // Safety Stock & ROP Formulas
    public function safetyStock(float $dailyStdDev, int $leadTimeDays, float $priorityFactor): int
    {
        $lead = max(1, $leadTimeDays);

        return (int) ceil(self::SERVICE_LEVEL_Z * $dailyStdDev * sqrt($lead) * $priorityFactor);
    }

    public function reorderPoint(float $dailyDemand, int $leadTimeDays, int $safetyStock): int
    {
        return (int) ceil($dailyDemand * max(1, $leadTimeDays) + $safetyStock);
    }

    public function targetStock(float $dailyDemand, int $leadTimeDays, int $safetyStock, int $reviewDays = self::DEFAULT_REVIEW_PERIOD_DAYS): int
    {
        return (int) ceil($dailyDemand * (max(1, $leadTimeDays) + $reviewDays) + $safetyStock);
    }

    // Order Quantities & Recalculation
    public function recommendedOrderQuantity(InventoryItem $item): int
    {
        $profile = $this->demandProfile($item->item_name);
        $priority = $this->priorityFactor($item->category);
        $safety = $this->safetyStock($profile['stddev'], $item->lead_time_days, $priority);
        $target = $this->targetStock($profile['daily'], $item->lead_time_days, $safety);

        return (int) max(0, $target - $item->stock_on_hand);
    }

    public function recalculateItem(InventoryItem $item): InventoryItem
    {
        $profile = $this->demandProfile($item->item_name);
        $priority = $this->priorityFactor($item->category);
        $safety = $this->safetyStock($profile['stddev'], $item->lead_time_days, $priority);

        $item->safety_stock = $safety;
        $item->reorder_point = $this->reorderPoint($profile['daily'], $item->lead_time_days, $safety);
        $item->target_stock = $this->targetStock($profile['daily'], $item->lead_time_days, $safety);
        $item->recalculated_at = now();
        $item->save();

        return $item;
    }

    public function recalculateAll(): int
    {
        return DB::transaction(function (): int {
            $items = InventoryItem::query()->orderBy('id')->get();

            foreach ($items as $item) {
                $this->recalculateItem($item);
            }

            return $items->count();
        });
    }

    // Restock Advisories
    /**
     * @return Collection<int, array<string,mixed>>
     */
    public function advisories(): Collection
    {
        return InventoryItem::query()
            ->with('supplier')
            ->orderBy('id')
            ->get()
            ->filter(fn (InventoryItem $item): bool => $item->needsReorder())
            ->map(function (InventoryItem $item): array {
                // One formula, shared with the inventory list and the resource.
                $cover = $this->daysRemaining($item);
                $orderQty = $this->recommendedOrderQuantity($item);

                return [
                    'id' => $item->id,
                    'item' => $item->item_name,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'on_hand' => $item->stock_on_hand,
                    'reorder_at' => $item->reorder_point,
                    'safety_stock' => $item->safety_stock,
                    'target_stock' => $item->target_stock,
                    'lead_time' => $item->lead_time_days,
                    'supplier' => $item->supplier?->name ?? '-',
                    'daily_demand' => $cover['daily_demand'],
                    'demand_source' => $cover['demand_source'],
                    'days_left' => $cover['days_left'],
                    'order_quantity' => $orderQty,
                    'order_quantity_label' => $this->quantityLabel($orderQty, $item->unit),
                    'on_hand_label' => $this->quantityLabel($item->stock_on_hand, $item->unit),
                    // Explicit call to action instead of a passive "Warning".
                    'status_label' => 'REORDER NOW',
                    'severity' => $item->stock_on_hand <= $item->safety_stock ? 'critical' : 'warning',
                ];
            })
            ->sortBy(fn (array $row): float => $row['days_left'] ?? PHP_FLOAT_MAX)
            ->values();
    }
}
