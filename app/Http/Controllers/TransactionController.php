<?php

namespace App\Http\Controllers;

use App\Data\CheckoutData;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ProductionQueueItemResource;
use App\Http\Resources\TransactionResource;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()->with(['customer', 'cashier']);

        $days = $request->integer('days', 0);
        if ($days > 0) {
            $query->whereDate('transaction_date', '>=', today()->subDays($days - 1));
        }

        $type = $request->string('type')->toString();
        if ($type !== '' && $type !== 'All') {
            $query->where('order_type', $type);
        }

        $pay = $request->string('pay')->toString();
        if ($pay !== '' && $pay !== 'All') {
            $query->where('payment_method', $pay);
        }

        return response()->json([
            'transactions' => TransactionResource::collection(
                $query->orderByDesc('transaction_date')
                    ->orderByDesc('transaction_time')
                    ->orderByDesc('id')
                    ->limit(200)
                    ->get()
            ),
        ]);
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkout->execute(
            CheckoutData::fromArray($request->validated()),
            $request->user(),
        );

        return response()->json([
            'transaction' => new TransactionResource($result['transaction']),
            'customer' => new CustomerResource($result['customer']),
            'totals' => $result['totals'],
            'queue' => ProductionQueueItemResource::collection(
                ProductionQueueItem::query()->activeToday()->orderBy('id')->limit(20)->get()
            ),
        ], 201);
    }
}
