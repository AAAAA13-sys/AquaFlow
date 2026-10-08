<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMovementIndexRequest;
use App\Http\Requests\StoreStockMovementRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\StockMovementResource;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Services\StockMovementService;

class StockMovementController extends Controller
{
    public function index(StockMovementIndexRequest $request, InventoryItem $inventory)
    {
        $data = $request->validated();
        $query = StockMovement::with(['user', 'supplier'])->where('inventory_item_id', $inventory->id);
        if ($data['search'] ?? '') {
            $query->where(function ($q) use ($data) {
                $q->where('notes', 'like', '%'.$data['search'].'%')->orWhere('type', 'like', '%'.$data['search'].'%')->orWhere('lot_number', 'like', '%'.$data['search'].'%')
                    ->orWhere('reason', 'like', '%'.str_replace(' ', '_', $data['search']).'%')
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', '%'.$data['search'].'%'))
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$data['search'].'%'));
            });
        }
        if ($data['type'] ?? null) {
            $query->where('type', $data['type']);
        }
        if ($data['from'] ?? null) {
            $query->whereDate('created_at', '>=', $data['from']);
        }
        if ($data['to'] ?? null) {
            $query->whereDate('created_at', '<=', $data['to']);
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
