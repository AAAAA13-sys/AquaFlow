<?php

namespace App\Http\Controllers;

use App\Http\Requests\LogReturnRequest;
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

        return response()->json([
            'customers' => CustomerResource::collection($query->orderBy('id')->get()),
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

    public function logReturn(LogReturnRequest $request, Customer $customer): JsonResponse
    {
        $customer->recordReturn($request->validated()['kind']);

        return response()->json(['customer' => new CustomerResource($customer->refresh())]);
    }
}
