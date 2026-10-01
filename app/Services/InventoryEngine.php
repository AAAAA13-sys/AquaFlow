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
                $profile = $this->demandProfile($item->item_name);
                $daysLeft = $profile['daily'] > 0
                    ? $item->stock_on_hand / $profile['daily']
                    : 999.0;

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
                    'daily_demand' => round($profile['daily'], 2),
                    'days_left' => round($daysLeft, 1),
                    'order_quantity' => $this->recommendedOrderQuantity($item),
                    'severity' => $item->stock_on_hand <= $item->safety_stock ? 'critical' : 'warning',
                ];
            })
            ->sortBy('days_left')
            ->values();
    }
}
