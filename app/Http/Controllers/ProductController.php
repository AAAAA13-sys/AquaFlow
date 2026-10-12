<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner-facing management of what the till sells.
 *
 * The catalog used to be seed-only: no screen could change a price or add an
 * item. This is that screen's API.
 *
 * Two rules shape it. First, checkout and availability are recipe-driven, so a
 * product with no consumables is a button that cannot be sold - creation is
 * refused rather than publishing a broken tile. Second, products are referenced
 * by transaction history, so nothing is ever deleted: items are hidden instead.
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

    public function store(StoreProductRequest $request, RecipeService $recipes): JsonResponse
    {
        $data = $request->validated();

        $product = DB::transaction(function () use ($data, $recipes): Product {
            $category = (string) $data['category'];

            $product = Product::query()->create([
                'id' => $this->uniqueSlug((string) $data['name']),
                'name' => $data['name'],
                'price' => $data['price'],
                'category' => $category,
                'container_kind' => $data['container_kind'],
                'is_active' => true,
                'sold_at_pos' => $data['sold_at_pos'] ?? true,
                // Supplies are counter sales; refills and containers can be delivered.
                'walk_in_only' => $data['walk_in_only']
                    ?? in_array($category, [Product::CATEGORY_CONSUMABLE, Product::CATEGORY_CLEANING], true),
                'inventory_item_id' => $data['inventory_item_id'] ?? null,
                'inventory_units_per_sale' => $data['inventory_units_per_sale'] ?? 1,
                'sort_order' => ((int) Product::query()->max('sort_order')) + 10,
            ]);

            if (! $recipes->ensureConsumables($product)) {
                // Thrown inside the transaction so no half-created product survives.
                throw ValidationException::withMessages([
                    'category' => 'This item would consume nothing, so the till could not sell it. Pick a category that consumes stock, or link a stock item.',
                ]);
            }

            return $product;
        });

        return response()->json(
            ['product' => new ProductResource($product->load('inventoryItem'))],
            201
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->fill($request->validated())->save();

        return response()->json([
            'product' => new ProductResource($product->fresh()->load('inventoryItem')),
        ]);
    }

    /**
     * A URL- and receipt-safe identifier. The primary key is a short string that
     * transaction history points at, so it is generated once and never changed.
     */
    private function uniqueSlug(string $name): string
    {
        $base = trim(Str::slug($name), '-');
        $base = $base === '' ? 'item' : Str::limit($base, 30, '');

        $slug = $base;
        $attempt = 1;

        while (Product::query()->whereKey($slug)->exists()) {
            $suffix = '-'.$attempt;
            $slug = Str::limit($base, 30 - strlen($suffix), '').$suffix;
            $attempt++;
        }

        return $slug;
    }
}
