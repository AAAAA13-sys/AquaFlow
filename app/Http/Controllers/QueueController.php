<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductionQueueItemResource;
use App\Models\ProductionQueueItem;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Production line queue: Unload -> Wash -> Fill -> Seal.
 */
class QueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:active,delivered'], 'search' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $completed = ($data['status'] ?? 'active') === 'delivered';
        $query = ProductionQueueItem::where('is_completed', $completed);
        if ($search = $data['search'] ?? null) {
            $query->where(fn ($q) => $q->where('receipt_number', 'like', '%'.$search.'%')->orWhere('customer_name', 'like', '%'.$search.'%'));
        }
        $rows = $query->orderBy('id', $completed ? 'desc' : 'asc')->paginate(50);

        return response()->json(['queue' => ProductionQueueItemResource::collection($rows), 'page' => $rows->currentPage(), 'has_more' => $rows->hasMorePages(), 'total' => $rows->total()]);
    }

    public function deliver(ProductionQueueItem $queueItem, QueueService $service): JsonResponse
    {
        return response()->json(['item' => new ProductionQueueItemResource($service->deliver($queueItem, (int) auth()->id()))]);
    }

    public function advance(ProductionQueueItem $queueItem): JsonResponse
    {
        $queueItem->advance();

        return response()->json([
            'item' => new ProductionQueueItemResource($queueItem),
            'queue' => ProductionQueueItemResource::collection($this->activeQueue()),
        ]);
    }

    /**
     * @return Collection<int, ProductionQueueItem>
     */
    private function activeQueue(): Collection
    {
        return ProductionQueueItem::query()->activeToday()->orderBy('id')->get();
    }
}
