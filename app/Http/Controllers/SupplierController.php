<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
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
        $supplier = app(SupplierService::class)->create($request->validated());

        return response()->json(['supplier' => new SupplierResource($supplier)], 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validated();

        app(SupplierService::class)->update($supplier, $data);

        return response()->json(['supplier' => new SupplierResource($supplier->refresh())]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        app(SupplierService::class)->delete($supplier);

        return response()->json(['ok' => true]);
    }
}
