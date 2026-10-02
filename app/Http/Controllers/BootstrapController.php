<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\DemandForecastResource;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductionQueueItemResource;
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $forecasts = DemandForecast::latestPerSeries();
        $refill = $forecasts->get('Refill Gallons');

        $payload = [
            'products' => ProductResource::collection(
                Product::query()->with('inventoryItem')->where('is_active', true)->orderBy('id')->get()
            ),
            'inventory' => InventoryItemResource::forItems(
                InventoryItem::query()->with('supplier')->orderBy('id')->get(),
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
                Transaction::query()
                    ->with(['customer', 'cashier'])
                    ->orderByDesc('transaction_date')
                    ->orderByDesc('transaction_time')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get()
            ),
            'queue' => ProductionQueueItemResource::collection(
                ProductionQueueItem::query()->activeToday()->orderBy('id')->limit(20)->get()
            ),
            'forecasts' => $forecasts->map(
                fn (DemandForecast $forecast): DemandForecastResource => new DemandForecastResource($forecast)
            )->all(),
            'history' => $refill?->historical_data ?? [],
            'history30' => $refill?->historical_data ?? [],
            'forecast7' => $refill?->forecasted_data ?? [],
            'model' => [
                'order' => $refill?->model_order ?? 'ARIMA(1,1,1)',
                'method' => $refill?->method ?? 'none',
                'aic' => (float) ($refill?->aic_score ?? 0),
                'mape' => (float) ($refill?->mape_score ?? 0),
                'mae' => (float) ($refill?->mae_score ?? 0),
                'rmse' => (float) ($refill?->rmse_score ?? 0),
                'adf' => $refill?->adf_pvalue,
                'ljung_box_pvalue' => $refill?->ljung_box_pvalue,
            ],
            'settings' => SystemSetting::allValues(),
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'station' => SystemSetting::value(SystemSetting::KEY_STATION_NAME, 'AquaFlow Station'),
            ],
        ];

        if ($request->user()?->isAdmin()) {
            $payload['users'] = UserResource::collection(
                User::query()->where('is_active', true)->orderBy('id')->get()
            );
        }

        return response()->json($payload);
    }
}
