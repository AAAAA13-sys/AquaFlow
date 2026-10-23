<?php

namespace App\Services;

use App\Models\DemandForecast;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InventoryEngine
{
    // Service Constants & Fallback Rates
    public const SERVICE_LEVEL_Z = 1.65;

    public const DEFAULT_REVIEW_PERIOD_DAYS = 7;

    // A forecast that has not been refreshed recently must not quietly drive
    // reorder points. The nightly job runs daily; two days allows one missed
    // run before the engine falls back to recorded sales.
    public const DEFAULT_FORECAST_MAX_AGE_DAYS = 2;

    // Weekend/peak-day demand can run roughly twice the weekly average. The
    // forecast mean alone would under-buffer those days, so the demand-noise
    // term is widened by at least this factor whenever the model is seasonal.
    public const DAY_OF_WEEK_UNCERTAINTY = 2.0;

    public const MAX_DAY_OF_WEEK_UNCERTAINTY = 3.0;

    // Last-resort rate, in PIECES per day, for an item that has neither a
    // forecast, a known consumption ratio, nor any recorded sales. It must stay
    // on a piece scale: refill volume is measured in containers (hundreds per
    // day here), so a volume-sized default would suggest ordering thousands of
    // seals for a station that uses a few dozen.
    public const FALLBACK_DAILY_DEMAND = 2.0;

    public const ASSUMED_DEMAND_CV = 0.3;

    // A POS jug is a five-gallon container; it is not a gallon. Filter capacity
    // is rated in gallons, so a 2,000-gallon filter treats 400 refill jugs.
    // Treating "1 jug" as "1 gallon" overstated filter demand five times.
    public const JUG_GALLONS = 5;

    public const FILTER_GALLON_RATING = 2000;

    public const JUGS_PER_FILTER = self::FILTER_GALLON_RATING / self::JUG_GALLONS;

    private const FALLBACK_DAILY_RATES = [
        'Soap' => 1.2,
        'Sponges' => 1.5,
        'Slim Jug' => 1.8,
        'Round Jug' => 1.4,
    ];

    /**
     * Consumable pieces used per jug sold.
     *
     * Consumables travel with the container, so recorded jug volume is the
     * honest basis for estimating them. One seal or cover per jug sold; a
     * sediment filter lasts 400 five-gallon jugs.
     */
    private const CONSUMPTION_RATIOS = [
        'Seal' => 1.0,
        'Cover' => 1.0,
        'Cap' => 1.0,
        'Filter' => 1 / self::JUGS_PER_FILTER,
        'Soap' => 0.2,
        'Sponge' => 0.2,
    ];

    /**
     * When false, stored forecasts are ignored and demand is rebuilt from
     * recorded sales. The nightly command uses this after a failed analytics
     * run so a stale cache can never keep driving reorder points.
     */
    private bool $useStoredForecasts = true;

    /** @var array<string, float|null> Per-request memo for demand lookups. */
    private array $recentJugsCache = [];

    // Demand Mapping & Profiles
    public function demandSeries(string $item): ?string
    {
        if (in_array($item, [
            'Clear Cover',
            'Shrink Seal (Circle Top Slim)',
            'Shrink Seal (Circle Back)',
            'Shrink Seal (Circle Nozzle)',
            'Shrink Seal (Circle Top Round)',
            '5 Gallon Slim Jug',
            '5 Gallon Round Jug',
            'SHRINK_SLIM',
            'SHRINK_ROUND',
            'CLEAR_COVER',
        ], true)) {
            return $item;
        }

        return match (true) {
            str_contains($item, 'Caps') => 'Non-Spill Caps',
            str_contains($item, 'Seals') => 'Heat Shrink Seals',
            str_contains($item, 'Filter') => 'Sediment Filters',
            default => null,
        };
    }

    /**
     * @return array{
     *     daily: float,
     *     stddev: float,
     *     source: string,
     *     error_variances?: list<float>,
     *     day_of_week_factor?: float,
     *     forecast_stale?: bool
     * }
     */
    public function demandProfile(string $item, ?bool $useStoredForecasts = null): array
    {
        $useStored = $useStoredForecasts ?? $this->useStoredForecasts;
        $stale = false;
        $series = $this->demandSeries($item);

        if ($series !== null && $useStored) {
            $forecast = DemandForecast::latestFor($series);
            if ($forecast !== null) {
                if ($forecast->isStale()) {
                    // A missed nightly job must not leave a frozen buffer in
                    // place. Fall through to recorded sales instead.
                    $stale = true;
                    Log::warning('Ignoring stale demand forecast; rebuilding from recorded sales.', [
                        'series' => $series,
                        'forecast_date' => $forecast->forecast_date?->format('Y-m-d'),
                        'age_days' => $forecast->ageInDays(),
                        'max_age_days' => $forecast->maxAgeDays(),
                    ]);
                } else {
                    $daily = $forecast->forecastAverage();
                    $stdDev = $forecast->residual_std > 0 ? $forecast->residual_std : $forecast->forecastStdDev();
                    if ($stdDev <= 0.0) {
                        $stdDev = $daily * self::ASSUMED_DEMAND_CV;
                    }

                    return [
                        'daily' => $daily,
                        'stddev' => $stdDev,
                        'source' => $series,
                        'error_variances' => $forecast->model_diagnostics['forecast_error_variances'] ?? [],
                        'day_of_week_factor' => $this->dayOfWeekFactor(
                            $forecast->model_diagnostics ?? [],
                            (string) $forecast->method,
                        ),
                        'forecast_stale' => false,
                    ];
                }
            }
        }

        // No usable forecast for this item. Estimate it from recorded unit
        // volume instead of dropping to a blanket default: a seal is consumed
        // per jug sold, so the sales ledger already holds the answer.
        $direct = $this->recentDailyItemUnits($item);
        if ($direct !== null && $direct > 0) {
            return [
                'daily' => $direct,
                'stddev' => $direct * self::ASSUMED_DEMAND_CV,
                'source' => 'recorded sales',
                'forecast_stale' => $stale,
            ];
        }

        $ratio = $this->consumptionRatio($item);
        $dailyJugs = $this->recentDailyJugs();
        if ($ratio !== null && $dailyJugs !== null && $dailyJugs > 0) {
            $daily = $dailyJugs * $ratio;

            return [
                'daily' => $daily,
                'stddev' => $daily * self::ASSUMED_DEMAND_CV,
                'source' => 'recorded sales',
                'forecast_stale' => $stale,
            ];
        }

        foreach (self::FALLBACK_DAILY_RATES as $needle => $rate) {
            if (str_contains($item, $needle)) {
                return [
                    'daily' => (float) $rate,
                    'stddev' => $rate * self::ASSUMED_DEMAND_CV,
                    'source' => 'assumed rate',
                    'forecast_stale' => $stale,
                ];
            }
        }

        return [
            'daily' => self::FALLBACK_DAILY_DEMAND,
            'stddev' => self::FALLBACK_DAILY_DEMAND * self::ASSUMED_DEMAND_CV,
            'source' => 'default',
            'forecast_stale' => $stale,
        ];
    }

    /**
     * Peak-day uncertainty multiplier.
     *
     * A seasonal model already predicts the weekly shape, but its residual
     * noise is estimated across all days. Weekend demand can run about twice
     * the weekly average, so the buffer is widened to at least that much and
     * capped so an outlier weekday cannot explode it.
     *
     * @param  array<string,mixed>  $diagnostics
     */
    public function dayOfWeekFactor(array $diagnostics, string $method = ''): float
    {
        $seasonal = (bool) ($diagnostics['seasonal'] ?? str_contains($method, 'seasonal'));
        if (! $seasonal) {
            return 1.0;
        }

        $peak = (float) ($diagnostics['peak_day_factor'] ?? 0.0);

        return min(
            self::MAX_DAY_OF_WEEK_UNCERTAINTY,
            max(self::DAY_OF_WEEK_UNCERTAINTY, $peak),
        );
    }

    /** Consumable pieces per refill container sold, or null if unknown. */
    private function consumptionRatio(string $item): ?float
    {
        foreach (self::CONSUMPTION_RATIOS as $needle => $ratio) {
            if (str_contains($item, $needle)) {
                return (float) $ratio;
            }
        }

        return null;
    }

    /**
     * Average jugs sold per day, across refills and brand-new jugs.
     *
     * Consumables are used by both kinds of sale, so both categories count.
     * Cached per request because demandProfile() runs once per item. Returns
     * null before the station has recorded any sale, so callers can tell
     * "no data yet" from "no demand".
     */
    private function recentDailyJugs(int $days = 14): ?float
    {
        $key = 'all:'.$days;
        if (array_key_exists($key, $this->recentJugsCache)) {
            return $this->recentJugsCache[$key];
        }

        $refill = $this->recentDailyJugsForCategory('refill', $days);
        $new = $this->recentDailyJugsForCategory('container', $days);

        return $this->recentJugsCache[$key] = ($refill === null && $new === null)
            ? null
            : (float) $refill + (float) $new;
    }

    /** Average units of one product category sold per day, or null if none. */
    private function recentDailyJugsForCategory(string $category, int $days = 14): ?float
    {
        $key = 'category:'.$category.':'.$days;
        if (array_key_exists($key, $this->recentJugsCache)) {
            return $this->recentJugsCache[$key];
        }

        $row = DB::table('transactions')
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->whereNull('transactions.voided_at')
            ->where('transactions.order_type', '!=', 'Debt Payment')
            ->whereDate('transactions.transaction_date', '>=', today()->subDays($days - 1))
            ->where('products.category', $category)
            ->selectRaw('SUM(transaction_items.quantity) AS units, COUNT(DISTINCT transactions.id) AS sales')
            ->first();

        return $this->recentJugsCache[$key] = ($row === null || (int) $row->sales === 0)
            ? null
            : (float) $row->units / $days;
    }

    /**
     * Average units of a specific stock item sold per day, or null if none.
     *
     * Brand-new jugs are ordinary merchandise, so their own sales are the
     * honest fallback - not the refill volume used for consumables.
     */
    private function recentDailyItemUnits(string $itemName, int $days = 14): ?float
    {
        $key = 'item:'.$itemName.':'.$days;
        if (array_key_exists($key, $this->recentJugsCache)) {
            return $this->recentJugsCache[$key];
        }

        $row = DB::table('transactions')
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->join('inventory', 'inventory.id', '=', 'products.inventory_item_id')
            ->whereNull('transactions.voided_at')
            ->where('transactions.order_type', '!=', 'Debt Payment')
            ->whereDate('transactions.transaction_date', '>=', today()->subDays($days - 1))
            ->where('inventory.item_name', $itemName)
            ->selectRaw('SUM(transaction_items.quantity) AS units, COUNT(DISTINCT transactions.id) AS sales')
            ->first();

        return $this->recentJugsCache[$key] = ($row === null || (int) $row->sales === 0)
            ? null
            : (float) $row->units / $days;
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
     * @return array{daily_demand: float, demand_source: string, forecast_stale: bool, days_left: float|null, days_exact: float|null}
     */
    public function daysRemaining(InventoryItem|int $item, ?bool $useStoredForecasts = null): array
    {
        $model = $item instanceof InventoryItem
            ? $item
            : InventoryItem::query()->findOrFail($item);

        $profile = $this->demandProfile($model->item_name, $useStoredForecasts);
        $daily = (float) $profile['daily'];

        // Demand of zero is unknown demand, not infinite stock. Report it as null
        // so the UI can say "no forecast yet" instead of dividing by zero.
        $exact = $daily > 0.0 ? $model->stock_on_hand / $daily : null;

        return [
            'daily_demand' => round($daily, 2),
            'demand_source' => (string) $profile['source'],
            'forecast_stale' => (bool) ($profile['forecast_stale'] ?? false),
            'days_exact' => $exact,
            'days_left' => $exact === null ? null : round($exact, 1),
        ];
    }

    /**
     * Same figure for many items, without N+1 queries.
     *
     * @param  iterable<int, InventoryItem>  $items
     * @return array<int, array{daily_demand: float, demand_source: string, forecast_stale: bool, days_left: float|null, days_exact: float|null}>
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
            default => str_ends_with($unit, 's') ? $unit : $unit.'s',
        };

        return $quantity.' '.$noun;
    }

    // Safety Stock & ROP Formulas
    /**
     * Buffer covering demand noise over the whole lead time plus forecast error.
     *
     * Two independent sources of uncertainty add in quadrature:
     *   - demand noise on every lead-time day, widened for peak days;
     *   - the model's own error for each forecast day.
     *
     * Omitting the first term is what made buffers too thin: a stable-looking
     * forecast still has day-to-day sales noise.
     *
     * @param  list<float>|array<int,float>  $errorVariances  Per-day ARIMA mean variance.
     */
    public function safetyStock(
        float $dailyStdDev,
        int $leadTimeDays,
        float $priorityFactor,
        array $errorVariances = [],
        float $dayOfWeekUncertainty = 1.0,
    ): int {
        $lead = max(1, $leadTimeDays);
        $dailyStdDev = max(0.0, $dailyStdDev);
        $dayOfWeekUncertainty = max(1.0, $dayOfWeekUncertainty);

        // Demand noise accumulates over every day of exposure.
        $variance = $lead * ($dailyStdDev * $dayOfWeekUncertainty) ** 2;

        // Forecast error accumulates from the stored per-day variances. Beyond
        // the stored horizon (supplier lead times longer than the forecast),
        // reuse the average stored daily variance instead of the last value.
        for ($day = 0; $day < $lead; $day++) {
            $variance += $this->forecastErrorVariance($errorVariances, $day);
        }

        return (int) ceil(self::SERVICE_LEVEL_Z * sqrt(max(0.0, $variance)) * $priorityFactor);
    }

    /**
     * Variance for one forecast day, falling back to the stored average after
     * the horizon ends.
     *
     * @param  list<float>|array<int,float>  $variances
     */
    private function forecastErrorVariance(array $variances, int $day): float
    {
        if ($variances === []) {
            return 0.0;
        }

        if (array_key_exists($day, $variances)) {
            return max(0.0, (float) $variances[$day]);
        }

        $usable = array_values(array_filter(
            array_map('floatval', $variances),
            fn (float $value): bool => $value >= 0.0,
        ));

        return $usable === [] ? 0.0 : array_sum($usable) / count($usable);
    }

    public function reorderPoint(float $dailyDemand, int $leadTimeDays, int $safetyStock): int
    {
        return (int) ceil($dailyDemand * max(1, $leadTimeDays) + $safetyStock);
    }

    public function targetStock(float $dailyDemand, int $leadTimeDays, int $safetyStock, int $reviewDays = self::DEFAULT_REVIEW_PERIOD_DAYS): int
    {
        return (int) ceil($dailyDemand * (max(1, $leadTimeDays) + $reviewDays) + $safetyStock);
    }

    private function profileSafetyStock(InventoryItem $item, array $profile): int
    {
        return $this->safetyStock(
            (float) $profile['stddev'],
            (int) $item->lead_time_days,
            $this->priorityFactor($item->category),
            $profile['error_variances'] ?? [],
            (float) ($profile['day_of_week_factor'] ?? 1.0),
        );
    }

    // Order Quantities & Recalculation
    public function recommendedOrderQuantity(InventoryItem $item): int
    {
        $profile = $this->demandProfile($item->item_name);
        $priority = $this->priorityFactor($item->category);
        $safety = $this->profileSafetyStock($item, $profile);
        $target = $this->targetStock($profile['daily'], $item->lead_time_days, $safety);

        return (int) max(0, $target - $item->stock_on_hand);
    }

    public function recalculateItem(InventoryItem $item): InventoryItem
    {
        $profile = $this->demandProfile($item->item_name);
        $priority = $this->priorityFactor($item->category);
        $safety = $this->profileSafetyStock($item, $profile);

        $item->safety_stock = $safety;
        $item->reorder_point = $this->reorderPoint($profile['daily'], $item->lead_time_days, $safety);
        $item->target_stock = $this->targetStock($profile['daily'], $item->lead_time_days, $safety);
        $item->recalculated_at = now();
        $item->save();

        return $item;
    }

    /** @param array<string,mixed> $data */
    public function updateItem(InventoryItem $inventory, array $data): void
    {
        DB::transaction(function () use ($inventory, $data): void {
            $inventory = InventoryItem::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            if ($inventory->retired_at !== null) {
                throw ValidationException::withMessages(['inventory' => 'This legacy stock item is retired.']);
            }
            $before = $inventory->stock_on_hand;
            // Full-field edits (owner-managed catalogue fields).
            if (array_key_exists('item_name', $data)) {
                $duplicate = InventoryItem::query()
                    ->where('item_name', $data['item_name'])
                    ->where('id', '!=', $inventory->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'item_name' => 'An item with this name already exists.',
                    ]);
                }

                $inventory->item_name = $data['item_name'];
            }

            foreach (['category', 'stock_on_hand', 'unit', 'supplier_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $inventory->{$field} = $data[$field];
                }
            }

            $leadChanged = false;
            if (array_key_exists('lead_time_days', $data)) {
                $inventory->lead_time_days = (int) $data['lead_time_days'];
                $leadChanged = true;
            }

            $inventory->save();

            if ($leadChanged || array_key_exists('item_name', $data) || array_key_exists('category', $data)) {
                $this->recalculateItem($inventory);
            }

            if (array_key_exists('direction', $data)) {
                $inventory->refresh();
                $delta = (int) $data['direction'] > 0
                    ? ($inventory->isPieces() ? 500 : 5)
                    : ($inventory->isPieces() ? -50 : -1);

                $inventory->stock_on_hand = max(0, $inventory->stock_on_hand + $delta);
                $inventory->save();
            }

            $delta = $inventory->stock_on_hand - $before;
            if ($delta !== 0) {
                StockMovement::create([
                    'inventory_item_id' => $inventory->id, 'type' => 'adjustment', 'qty' => $delta,
                    'user_id' => auth()->id(), 'reason' => $data['reason'] ?? 'count_correction',
                    'notes' => $data['notes'] ?? 'Inventory count correction',
                ]);
                $this->recalculateItem($inventory);
            }
        });
    }

    /**
     * Rebuild every threshold.
     *
     * Pass false after a failed forecast refresh so a cached (possibly stale)
     * forecast cannot keep the old reorder points alive. The setting is
     * restored afterwards so a shared service instance does not leak it into
     * later reads in the same request.
     */
    public function recalculateAll(bool $useStoredForecasts = true): int
    {
        $previous = $this->useStoredForecasts;
        $this->useStoredForecasts = $useStoredForecasts;

        try {
            return DB::transaction(function (): int {
                $items = InventoryItem::query()->current()->orderBy('id')->get();

                foreach ($items as $item) {
                    $this->recalculateItem($item);
                }

                return $items->count();
            });
        } finally {
            $this->useStoredForecasts = $previous;
        }
    }

    // Restock Advisories
    /**
     * @return Collection<int, array<string,mixed>>
     */
    public function advisories(): Collection
    {
        return InventoryItem::query()
            ->current()
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
                    'forecast_stale' => $cover['forecast_stale'],
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
