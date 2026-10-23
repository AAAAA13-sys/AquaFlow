<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\DemandForecastResource;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\ProductionQueueItemResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionQueueItem;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $inventory,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $forecasts = DemandForecast::latestPerSeries();
        $refill = $forecasts->get('Refill Jugs');
        $refillModel = $refill ? (new DemandForecastResource($refill))->resolve($request)['model'] : [];

        // The forecast tier is optional, so a station that has never run the
        // ARIMA service has no historical_data at all. Recorded sales are the
        // source of truth either way, so fall back to the last 30 days of
        // actual refill volume rather than reporting an empty history.
        $history = $refill?->historical_data ?? [];
        $historySource = 'forecast';
        if ($history === []) {
            $history = $this->recordedSalesHistory();
            $historySource = $history === [] ? 'none' : 'sales';
        }

        $payload = [
            'products' => ProductResource::collection(
                Product::query()->with('inventoryItem')->where('is_active', true)
                    ->orderBy('sort_order')->orderBy('id')->get()
            ),
            'inventory' => InventoryItemResource::forItems(
                InventoryItem::query()->current()->with('supplier')->orderBy('id')->get(),
                $this->inventory
            ),
            'advisories' => $this->inventory->advisories(),
            'customers' => CustomerResource::collection(
                Customer::query()->orderBy('id')->get()
            ),
            'suppliers' => SupplierResource::collection(
                Supplier::query()->orderBy('id')->get()
            ),
            'transactions' => TransactionResource::collection(
                Transaction::query()->whereNull('voided_at')
                    ->visibleTo($request->user())
                    ->with(['customer', 'cashier', 'items'])
                    ->newestFirst()
                    ->limit(50)
                    ->get()
            ),
            'queue' => ProductionQueueItemResource::collection(
                ProductionQueueItem::query()->with('transaction.items')->activeToday()->orderBy('id')->get()
            ),
            'forecasts' => DemandForecastResource::forSeries($forecasts),
            'history' => $history,
            'history30' => $history,
            'forecast7' => $refill?->forecasted_data ?? [],
            'model' => [
                'order' => $refill?->model_order ?? 'ARIMA(1,1,1)',
                'method' => $refill?->method ?? 'none',
                'aic' => (float) ($refill?->aic_score ?? 0),
                'mape' => $refillModel['mape'] ?? null,
                'mae' => $refillModel['mae'] ?? null,
                'rmse' => $refillModel['rmse'] ?? null,
                'adf' => $refill?->adf_pvalue,
                'ljung_box_pvalue' => $refill?->ljung_box_pvalue,
            ],
            'settings' => SystemSetting::allValues(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'history_source' => $historySource,
                'sales_today_total' => (float) Transaction::whereNull('voided_at')->whereDate('transaction_date', today())->where('order_type', '!=', 'Debt Payment')->sum('total_amount'),
                'sales_today_count' => Transaction::whereNull('voided_at')->whereDate('transaction_date', today())->where('order_type', '!=', 'Debt Payment')->count(),
                // Additive: a short daily revenue series so the KPI cards can
                // draw a trend line and answer "is today up or down?". Existing
                // consumers of this payload are unaffected.
                'daily_revenue' => $this->recordedDailyRevenue(14),
                'station' => SystemSetting::value(SystemSetting::KEY_STATION_NAME, 'AquaFlow Station'),
            ],
        ];

        if ($request->user()?->isAdmin()) {
            $userQuery = User::query()->where('is_bootstrap', false)->whereIn('role', User::ROLES);
            if (! $request->user()->isSuperAdmin()) {
                $userQuery->where('role', '!=', User::ROLE_SUPER_ADMIN);
            }
            $payload['users'] = UserResource::collection(
                $userQuery->orderBy('id')->get()
            );
        }

        if (! $request->user()->isAdmin()) {
            unset($payload['forecasts'], $payload['history'], $payload['history30'], $payload['forecast7'], $payload['model']);
            unset($payload['meta']['sales_today_total'], $payload['meta']['sales_today_count'], $payload['meta']['daily_revenue']);
        }

        return response()->json($payload);
    }

    /**
     * Daily refill jugs for the last 30 days, oldest first.
     *
     * Days with no recorded sales are kept as zero so the series stays a
     * continuous run of calendar days: the charts and the weekday/weekend
     * averages both index by position, so a missing day would silently shift
     * every later value onto the wrong weekday.
     *
     * @return list<float>
     */
    private function recordedSalesHistory(int $days = 30): array
    {
        $from = today()->subDays($days - 1);

        $byDate = [];
        foreach (Transaction::query()->whereNull('voided_at')
            ->where('order_type', '!=', 'Debt Payment')
            ->whereDate('transaction_date', '>=', $from)
            ->get(['transaction_date', 'gallons_volume']) as $transaction) {
            $date = $transaction->transaction_date?->format('Y-m-d');
            if ($date === null) {
                continue;
            }
            $byDate[$date] = ($byDate[$date] ?? 0.0) + Transaction::refillJugsFromLabel($transaction->gallons_volume);
        }

        // A store with no sales at all still gets the honest empty state.
        if ($byDate === []) {
            return [];
        }

        $series = [];
        for ($day = 0; $day < $days; $day++) {
            $series[] = round($byDate[$from->copy()->addDays($day)->format('Y-m-d')] ?? 0.0, 2);
        }

        return $series;
    }

    /**
     * Recorded revenue per day, oldest first, for the KPI trend lines.
     *
     * Same shape and exclusions as recordedSalesHistory(): cash and card
     * sales count, debt settlements do not, because a collection is not new
     * trading.
     *
     * @return list<float>
     */
    private function recordedDailyRevenue(int $days = 14): array
    {
        $from = today()->subDays($days - 1);

        $byDate = [];
        foreach (Transaction::query()->whereNull('voided_at')
            ->where('order_type', '!=', 'Debt Payment')
            ->whereDate('transaction_date', '>=', $from)
            ->get(['transaction_date', 'total_amount']) as $transaction) {
            $date = $transaction->transaction_date?->format('Y-m-d');
            if ($date === null) {
                continue;
            }
            $byDate[$date] = ($byDate[$date] ?? 0.0) + (float) $transaction->total_amount;
        }

        if ($byDate === []) {
            return [];
        }

        $series = [];
        for ($day = 0; $day < $days; $day++) {
            $series[] = round($byDate[$from->copy()->addDays($day)->format('Y-m-d')] ?? 0.0, 2);
        }

        return $series;
    }
}
