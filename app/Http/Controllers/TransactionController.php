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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()->with(['customer', 'cashier', 'items']);

        $days = $request->integer('days', 0);
        if ($request->has('days')) {
            $query->whereDate('transaction_date', '>=', today()->subDays(max(1, $days) - 1));
        }

        $type = $request->string('type')->toString();
        if ($type !== '' && $type !== 'All') {
            $query->where('order_type', $type);
        }

        $pay = $request->string('pay')->toString();
        if ($pay !== '' && $pay !== 'All') {
            $query->where('payment_method', $pay);
        }

        if ($request->boolean('paginated')) {
            $page = $query->newestFirst()->paginate(200);

            return response()->json(['transactions' => TransactionResource::collection($page->items()), 'has_more' => $page->hasMorePages()]);
        }

        return response()->json(['transactions' => TransactionResource::collection($query->newestFirst()->limit(200)->get())]);
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkout->execute(
            CheckoutData::fromArray($request->validated()),
            $request->user(),
        );

        return response()->json([
            'transaction' => new TransactionResource($result['transaction']->load(['customer', 'cashier', 'items'])),
            'customer' => new CustomerResource($result['customer']),
            'totals' => $result['totals'],
            'queue' => ProductionQueueItemResource::collection(
                ProductionQueueItem::query()->activeToday()->orderBy('id')->get()
            ),
        ], 201);
    }
}
