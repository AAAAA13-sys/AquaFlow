<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockMovementRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\StockMovementResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Services\StockMovementService;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request, InventoryItem $inventory)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'order' => ['nullable', 'in:newest,oldest'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = StockMovement::with(['user', 'supplier'])->where('inventory_item_id', $inventory->id);
        if ($data['search'] ?? '') {
            $query->where(function ($q) use ($data) {
                $q->where('notes', 'like', '%'.$data['search'].'%')->orWhere('type', 'like', '%'.$data['search'].'%')->orWhere('lot_number', 'like', '%'.$data['search'].'%');
            });
        }
        $dir = ($data['order'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        return StockMovementResource::collection($query->orderBy('id', $dir)->paginate(20)->withQueryString());
    }

    public function store(StoreStockMovementRequest $request, InventoryItem $inventory, StockMovementService $service)
    {
        $movement = $service->record($inventory, $request->validated(), $request->user());

        return response()->json(['movement' => new StockMovementResource($movement->load(['user', 'supplier'])), 'item' => new InventoryItemResource($inventory->refresh()->load('supplier'))], 201);
    }
}
