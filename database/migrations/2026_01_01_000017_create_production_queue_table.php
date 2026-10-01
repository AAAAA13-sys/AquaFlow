<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production line queue (Unload -> Wash -> Fill -> Seal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_queue', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 30)->index();
            $table->string('customer_name', 150);
            $table->unsignedInteger('stage')->default(0);
            $table->unsignedInteger('elapsed_minutes')->default(0);
            $table->string('items_description', 100)->default('5 gal');
            $table->enum('order_type', ['Walk-in', 'Delivery'])->default('Walk-in');
            $table->boolean('is_completed')->default(false);
            $table->timestamps();

            $table->index(['is_completed', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_queue');
    }
};
