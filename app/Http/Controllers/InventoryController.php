<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryRequest;
use App\Http\Requests\UpdateInventoryRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

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

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = InventoryItem::query()->create([
            'item_name' => $data['item_name'],
            'category' => $data['category'],
            'stock_on_hand' => $data['stock_on_hand'] ?? 0,
            'unit' => $data['unit'] ?? 'pcs',
            'lead_time_days' => $data['lead_time_days'] ?? 2,
            'supplier_id' => $data['supplier_id'] ?? null,
        ]);

        $this->engine->recalculateItem($item);

        return response()->json([
            'item' => new InventoryItemResource($item->refresh()->load('supplier')),
            'advisories' => $this->engine->advisories(),
        ], 201);
    }

    public function update(UpdateInventoryRequest $request, InventoryItem $inventory): JsonResponse
    {
        $data = $request->validated();

        // Full-field edits (owner-managed catalogue fields).
        if (array_key_exists('item_name', $data)) {
            $duplicate = InventoryItem::query()
                ->where('item_name', $data['item_name'])
                ->where('id', '!=', $inventory->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'item_name' => 'An item with this name already exists.',
                ]);
            }

            $inventory->item_name = $data['item_name'];
        }

        foreach (['category', 'stock_on_hand', 'unit', 'supplier_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $inventory->{$field} = $data[$field];
            }
        }

        $leadChanged = false;
        if (array_key_exists('lead_time_days', $data)) {
            $inventory->lead_time_days = (int) $data['lead_time_days'];
            $leadChanged = true;
        }

        $inventory->save();

        if ($leadChanged || array_key_exists('item_name', $data) || array_key_exists('category', $data)) {
            $this->engine->recalculateItem($inventory);
        }

        if (array_key_exists('direction', $data)) {
            $inventory->refresh();
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

    public function destroy(InventoryItem $inventory): JsonResponse
    {
        $inventory->delete();

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
