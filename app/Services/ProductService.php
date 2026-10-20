<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating and removing a till item.
 *
 * This logic used to live in `ProductController`. It moved here because the same
 * operation now has two callers: the owner writing directly, and the applier
 * that runs when the owner approves a manager's request. Keeping one
 * implementation is what stops the two paths drifting - a fix to the recipe rule
 * or the deletion guard must reach both.
 *
 * Both operations assume they are already inside the caller's transaction, so
 * they do not open their own. The controller wraps the direct write, and the
 * approval applier runs inside the decision transaction.
 */
class ProductService
{
    public function __construct(private readonly RecipeService $recipes) {}

    /**
     * Create one till item, deriving its consumption recipe.
     *
     * A product with no consumables is a button the till cannot sell, so
     * creation is refused rather than publishing a broken tile. The refusal
     * happens inside the caller's transaction, so no half-created product
     * survives.
     *
     * @param  array<string,mixed>  $data  the validated create payload
     */
    public function create(array $data): Product
    {
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

        if (! empty($data['consumables'])) {
            $this->recipes->replace($product, $data['consumables']);
        } elseif (! $this->recipes->ensureConsumables($product)) {
            throw ValidationException::withMessages([
                'category' => 'This item would consume nothing, so the till could not sell it. Pick a category that consumes stock, or link a stock item.',
            ]);
        }

        return $product;
    }

    /**
     * Remove one till item.
     *
     * A product with recorded sales is evidence and cannot be deleted; it can be
     * hidden from the till instead. The recipe rows go with it, because nothing
     * references them once the product is gone.
     */
    public function delete(Product $product): void
    {
        if ($product->items()->exists()) {
            throw ValidationException::withMessages([
                'product' => 'Cannot remove "' . $product->name . '" because it has recorded sales transactions. You can hide it from the till instead.',
            ]);
        }

        DB::table('product_consumables')->where('product_id', $product->id)->delete();
        $product->delete();
    }

    /**
     * A URL- and receipt-safe identifier.
     *
     * The primary key is a short string that transaction history points at, so
     * it is generated once and never changed.
     */
    public function uniqueSlug(string $name): string
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
