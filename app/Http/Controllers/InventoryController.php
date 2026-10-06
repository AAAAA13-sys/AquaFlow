<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\InventoryEngine;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryEngine $engine,
    ) {}

    public function index(): JsonResponse
    {
        $items = InventoryItem::query()->with('supplier')->orderBy('id')->get();

        return response()->json([
            'inventory' => InventoryItemResource::forItems($items, $this->engine),
            'advisories' => $this->engine->advisories(),
        ]);
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = app(StockMovementService::class)->createItem($data, $request->user());

        return response()->json([
            'item' => new InventoryItemResource($item->refresh()->load('supplier')),
            'advisories' => $this->engine->advisories(),
        ], 201);
    }

    public function update(UpdateInventoryRequest $request, InventoryItem $inventory): JsonResponse
    {
        $data = $request->validated();

        $this->engine->updateItem($inventory, $data);

        return response()->json([
            'item' => new InventoryItemResource($inventory->refresh()->load('supplier')),
            'advisories' => $this->engine->advisories(),
        ]);
    }

    public function destroy(InventoryItem $inventory): JsonResponse
    {
        app(StockMovementService::class)->deleteItem($inventory);

        return response()->json([
            'ok' => true,
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
