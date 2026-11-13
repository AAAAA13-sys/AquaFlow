<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelPurchaseOrderRequest;
use App\Http\Requests\ReceivePurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                PurchaseOrder::DRAFT, PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL,
                PurchaseOrder::RECEIVED, PurchaseOrder::CANCELLED,
            ])],
        ]);

        $query = PurchaseOrder::query()->with(['supplier', 'creator', 'lines.item'])->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json(['purchase_orders' => PurchaseOrderResource::collection($query->limit(200)->get())]);
    }

    public function suggestions(): JsonResponse
    {
        return response()->json(['suggestions' => $this->orders->suggestions()]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->orders->create($request->user(), $request->validated());

        return response()->json(['purchase_order' => new PurchaseOrderResource($po)], 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['supplier', 'creator', 'lines.item']);

        return response()->json(['purchase_order' => new PurchaseOrderResource($purchaseOrder)]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->orders->update($request->user(), $purchaseOrder, $request->validated());

        return response()->json(['purchase_order' => new PurchaseOrderResource($po)]);
    }

    public function markOrdered(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->orders->markOrdered($request->user(), $purchaseOrder);

        return response()->json(['purchase_order' => new PurchaseOrderResource($po)]);
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json($this->orders->receive($request->user(), $purchaseOrder, $request->validated()));
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->orders->cancel($request->user(), $purchaseOrder, $request->validated()['reason']);

        return response()->json(['purchase_order' => new PurchaseOrderResource($po)]);
    }
}
