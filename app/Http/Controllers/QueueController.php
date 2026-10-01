<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductionQueueItemResource;
use App\Models\ProductionQueueItem;
use Illuminate\Http\JsonResponse;

/**
 * Production line queue: Unload -> Wash -> Fill -> Seal.
 */
class QueueController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['queue' => ProductionQueueItemResource::collection($this->activeQueue())]);
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
     * @return \Illuminate\Database\Eloquent\Collection<int, ProductionQueueItem>
     */
    private function activeQueue(): \Illuminate\Database\Eloquent\Collection
    {
        return ProductionQueueItem::query()->activeToday()->orderBy('id')->limit(20)->get();
    }
}
