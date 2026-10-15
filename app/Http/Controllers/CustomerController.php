<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettleDebtRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\TransactionResource;
use App\Models\Customer;
use App\Services\CustomerLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()->search($request->string('q')->toString());

        match ($request->string('filter')->toString()) {
            'bottles' => $query->withBottles(),
            'cash' => $query->withDebt(),
            default => $query,
        };

        // The synthetic walk-in record anchors walk-in sales; it is never someone a
        // cashier searches for, and stage 1 has a dedicated Quick Walk-In button.
        // Rows created before `system_key` existed are matched by their legacy
        // display name instead.
        if ($request->boolean('exclude_guest')) {
            $query->where(function ($inner): void {
                $inner->whereNull('system_key')->orWhere('system_key', '!=', 'walk-in');
            })->where('name', '!=', 'Walk-in Guest');
        }

        // The terminal searches as the cashier types and needs only a short,
        // explicitly ordered result list, so it sends `order` and `limit`.
        // Admin callers send neither and keep the original full, id-ordered
        // response.
        match ($request->string('order')->toString()) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderBy('id'),
        };

        $limit = $request->integer('limit', 0);
        if ($limit > 0) {
            $query->limit(min($limit, 50));
        }

        return response()->json([
            'customers' => CustomerResource::collection($query->get()),
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::query()->create([
            'name' => $data['name'],
            'address' => ($data['address'] ?? null) ?: '-',
            'contact' => ($data['contact'] ?? null) ?: '-',
        ]);

        return response()->json(['customer' => new CustomerResource($customer)], 201);
    }

    public function settle(SettleDebtRequest $request, Customer $customer): JsonResponse
    {
        $result = app(CustomerLedgerService::class)->settle($customer, (float) $request->validated()['amount'], $request->user(), $request->validated()['submission_key']);

        return response()->json([
            'replayed' => $result['replayed'],
            'applied' => $result['applied'],
            'transaction' => new TransactionResource($result['transaction']),
            'customer' => new CustomerResource($result['customer']),
        ]);
    }
}
