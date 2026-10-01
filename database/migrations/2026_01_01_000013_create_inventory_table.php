<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumable inventory with dynamic thresholds (Phase 4 engine writes these).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->string('item_name', 150)->unique();
            $table->enum('category', ['Consumable', 'Filtration', 'Cleaning', 'Asset']);
            $table->integer('stock_on_hand')->default(0);
            $table->string('unit', 20)->default('pcs');
            $table->unsignedInteger('safety_stock')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->unsignedInteger('target_stock')->default(0);
            $table->unsignedInteger('lead_time_days')->default(2);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
