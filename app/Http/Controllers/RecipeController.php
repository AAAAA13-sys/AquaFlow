<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRecipeRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\RecipeResource;
use App\Models\Product;
use App\Services\RecipeService;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index()
    {
        return response()->json(['products' => ProductResource::collection(Product::orderBy('id')->get()), 'recipes' => RecipeResource::collection(DB::table('product_consumables')->orderBy('product_id')->orderBy('inventory_item_id')->get())]);
    }

    public function update(UpdateRecipeRequest $request, Product $product, RecipeService $service)
    {
        $service->replace($product, $request->validated('consumables'));

        return response()->json(['ok' => true]);
    }
}
