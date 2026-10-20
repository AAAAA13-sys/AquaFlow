<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ApprovalService;
use App\Services\AuditLogService;
use App\Services\ProductService;
use App\Support\ApprovalTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner-facing management of what the till sells.
 *
 * The catalog used to be seed-only: no screen could change a price or add an
 * item. This is that screen's API.
 *
 * Two rules shape it. First, checkout and availability are recipe-driven, so a
 * product with no consumables is a button that cannot be sold - creation is
 * refused rather than publishing a broken tile. Second, products with historical
 * sales transactions cannot be deleted to preserve audit trails, while unreferenced
 * products can be removed.
 */
class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'products' => ProductResource::collection(
                Product::query()->with('inventoryItem')->orderBy('sort_order')->orderBy('id')->get()
            ),
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // A manager proposes a new till item; the owner writes it directly.
        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::PRODUCT_CREATE, $data,
                $request->string('reason')->toString() ?: null);
        }

        $product = DB::transaction(function () use ($data, $user): Product {
            $product = app(ProductService::class)->create($data);

            app(AuditLogService::class)->record($user, 'create', $product, null,
                ['name' => $product->name, 'price' => (float) $product->price, 'category' => $product->category]);

            return $product;
        });

        return response()->json(
            ['product' => new ProductResource($product->load('inventoryItem')), 'pending_approval' => false],
            201
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::PRODUCT_UPDATE, $data,
                $request->string('reason')->toString() ?: null, (string) $product->id);
        }

        DB::transaction(function () use ($product, $data, $user): void {
            /** @var Product $product */
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = [];
            foreach (array_keys($data) as $key) {
                $before[$key] = $product->getAttribute($key);
            }

            $product->fill($data)->save();

            app(AuditLogService::class)->record($user, 'update', $product, $before,
                array_intersect_key($product->getAttributes(), $before));
        });

        return response()->json([
            'product' => new ProductResource($product->fresh()->load('inventoryItem')),
            'pending_approval' => false,
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::PRODUCT_DELETE,
                ['name' => $product->name], $request->string('reason')->toString() ?: null, (string) $product->id);
        }

        DB::transaction(function () use ($product, $user): void {
            app(AuditLogService::class)->record($user, 'delete', $product,
                ['name' => $product->name, 'price' => (float) $product->price], null);
            app(ProductService::class)->delete($product);
        });

        return response()->json(['ok' => true]);
    }
}
