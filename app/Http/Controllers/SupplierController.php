<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\ApprovalService;
use App\Services\AuditLogService;
use App\Services\SupplierService;
use App\Support\ApprovalTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::SUPPLIER_CREATE, $data,
                $request->string('reason')->toString() ?: null);
        }

        $supplier = app(SupplierService::class)->create($data);

        app(AuditLogService::class)->record($user, 'create', $supplier, null,
            ['name' => $supplier->name, 'contact' => $supplier->contact]);

        return response()->json(['supplier' => new SupplierResource($supplier), 'pending_approval' => false], 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::SUPPLIER_UPDATE, $data,
                $request->string('reason')->toString() ?: null, (string) $supplier->id);
        }

        $before = [];
        foreach (array_keys($data) as $key) {
            $before[$key] = $supplier->getAttribute($key);
        }

        app(SupplierService::class)->update($supplier, $data);

        $supplier->refresh();
        app(AuditLogService::class)->record($user, 'update', $supplier, $before,
            array_intersect_key($supplier->getAttributes(), $before));

        return response()->json(['supplier' => new SupplierResource($supplier), 'pending_approval' => false]);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::SUPPLIER_DELETE,
                ['name' => $supplier->name], $request->string('reason')->toString() ?: null, (string) $supplier->id);
        }

        DB::transaction(function () use ($supplier, $user): void {
            app(AuditLogService::class)->record($user, 'delete', $supplier, ['name' => $supplier->name], null);
            app(SupplierService::class)->delete($supplier);
        });

        return response()->json(['ok' => true, 'pending_approval' => false]);
    }
}
