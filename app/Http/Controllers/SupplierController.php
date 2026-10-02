<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'suppliers' => SupplierResource::collection(
                Supplier::query()->orderBy('id')->get()
            ),
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $data = $request->validated();

        $supplier = Supplier::query()->create([
            'name' => $data['name'],
            'supplied_items' => $data['supplied_items'],
            'lead_time_days' => $data['lead_time_days'] ?? 2,
            'contact' => ($data['contact'] ?? null) ?: '-',
            'last_delivery' => $data['last_delivery'] ?? null,
        ]);

        return response()->json(['supplier' => new SupplierResource($supplier)], 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('contact', $data) && ($data['contact'] === null || $data['contact'] === '')) {
            $data['contact'] = '-';
        }

        $supplier->fill($data);
        $supplier->save();

        return response()->json(['supplier' => new SupplierResource($supplier->refresh())]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(['ok' => true]);
    }
}
