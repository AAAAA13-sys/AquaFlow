<?php

namespace App\Http\Controllers;

use App\Http\Requests\LogReturnRequest;
use App\Http\Requests\SettleDebtRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $applied = DB::transaction(function () use ($request, $customer): float {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->find($customer->id);

            if ($locked->debt_balance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Customer has no outstanding balance.',
                ]);
            }

            $applied = min((float) $request->validated()['amount'], $locked->debt_balance);
            $locked->debt_balance = round($locked->debt_balance - $applied, 2);
            $locked->save();

            // Record the settlement so it appears in the cashier's history.
            $highest = (int) DB::table('transactions')
                ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(receipt_number, 4) AS UNSIGNED)), 1010) AS highest')
                ->value('highest');

            $receipt = 'OR-' . ($highest + 1);

            DB::table('transactions')->insert([
                'receipt_number' => $receipt,
                'customer_id' => $locked->id,
                'cashier_id' => $request->user()->id,
                'order_type' => 'Debt Payment',
                'gallons_volume' => '0 gal',
                'subtotal_amount' => 0,
                'vat_amount' => 0,
                'auto_discount' => 0,
                'manual_discount' => 0,
                'total_amount' => $applied,
                'payment_method' => 'Cash',
                'cash_tendered' => $applied,
                'cash_change' => 0,
                'transaction_date' => now()->toDateString(),
                'transaction_time' => now()->toTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $applied;
        });

        return response()->json([
            'applied' => $applied,
            'customer' => new CustomerResource($customer->refresh()),
        ]);
    }

    public function logReturn(LogReturnRequest $request, Customer $customer): JsonResponse
    {
        $kind = $request->validated()['kind'];

        DB::transaction(function () use ($customer, $kind): void {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->find($customer->id);

            $pending = $kind === 'slim' ? $locked->pendingSlim() : $locked->pendingRound();

            if ($pending <= 0) {
                throw ValidationException::withMessages([
                    'kind' => "No pending {$kind} bottles to return.",
                ]);
            }

            if ($kind === 'slim') {
                $locked->returned_slim += 1;
            } else {
                $locked->returned_round += 1;
            }

            $locked->save();
        });

        return response()->json(['customer' => new CustomerResource($customer->refresh())]);
    }
}
