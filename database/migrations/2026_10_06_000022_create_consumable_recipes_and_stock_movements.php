<?php

use App\Services\RecipeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_consumables', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 30);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedInteger('qty_per_sale');
            $table->timestamps();
            $table->unique(['product_id', 'inventory_item_id']);
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory')->restrictOnDelete();
            $table->enum('type', ['sale', 'restock', 'adjustment', 'damage']);
            $table->integer('qty');
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('lot_number', 100)->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('reason', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['inventory_item_id', 'created_at', 'id']);
        });
        app(RecipeService::class)->installDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_consumables');
    }
};
