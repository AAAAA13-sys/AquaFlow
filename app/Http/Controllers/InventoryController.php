<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $engine,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = InventoryItem::query()->with('supplier')->orderBy('id')->get();

        return response()->json([
            'inventory' => InventoryItemResource::forItems($items, $this->engine),
            'advisories' => $this->engine->advisories(),
        ]);
    }

    public function update(UpdateInventoryRequest $request, InventoryItem $inventory): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('lead_time_days', $data)) {
            $inventory->lead_time_days = (int) $data['lead_time_days'];
            $inventory->save();

            $this->engine->recalculateItem($inventory);
        }

        if (array_key_exists('direction', $data)) {
            $delta = (int) $data['direction'] > 0
                ? ($inventory->isPieces() ? 500 : 5)
                : ($inventory->isPieces() ? -50 : -1);

            $inventory->stock_on_hand = max(0, $inventory->stock_on_hand + $delta);
            $inventory->save();
        }

        return response()->json([
            'item' => new InventoryItemResource($inventory->refresh()->load('supplier')),
            'advisories' => $this->engine->advisories(),
        ]);
    }

    public function recalculate(): JsonResponse
    {
        $updated = $this->engine->recalculateAll();
        $items = InventoryItem::query()->with('supplier')->orderBy('id')->get();

        return response()->json([
            'updated' => $updated,
            'inventory' => InventoryItemResource::forItems($items, $this->engine),
            'advisories' => $this->engine->advisories(),
        ]);
    }
}
