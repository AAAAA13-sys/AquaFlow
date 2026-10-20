<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRecipeRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\RecipeResource;
use App\Models\Product;
use App\Services\ApprovalService;
use App\Services\AuditLogService;
use App\Services\RecipeService;
use App\Support\ApprovalTypes;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index()
    {
        return response()->json(['products' => ProductResource::collection(Product::orderBy('id')->get()), 'recipes' => RecipeResource::collection(DB::table('product_consumables')->orderBy('product_id')->orderBy('inventory_item_id')->get())]);
    }

    public function update(UpdateRecipeRequest $request, Product $product, RecipeService $service)
    {
        $user = $request->user();
        $payload = ['consumables' => $request->validated('consumables')];

        // A manager proposes the recipe change; the owner writes it directly.
        if (! $user->isSuperAdmin()) {
            return app(ApprovalService::class)->stage($user, ApprovalTypes::RECIPE_UPDATE, $payload,
                $request->string('reason')->toString() ?: null, (string) $product->id);
        }

        $before = DB::table('product_consumables')->where('product_id', $product->id)
            ->orderBy('inventory_item_id')->pluck('qty_per_sale', 'inventory_item_id')->all();

        $service->replace($product, $payload['consumables']);

        app(AuditLogService::class)->record($user, 'update', $product,
            ['recipe' => $before],
            ['recipe' => DB::table('product_consumables')->where('product_id', $product->id)
                ->orderBy('inventory_item_id')->pluck('qty_per_sale', 'inventory_item_id')->all()]);

        return response()->json(['ok' => true, 'pending_approval' => false]);
    }
}
