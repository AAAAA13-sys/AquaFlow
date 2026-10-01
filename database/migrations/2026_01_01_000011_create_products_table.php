<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS product catalog. Slug-style string primary keys ('slim', 'newS', ...)
 * because the front-end references products by their identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->string('id', 30)->primary();
            $table->string('name', 100);
            $table->decimal('price', 10, 2)->default(0);
            $table->enum('category', ['refill', 'consumable', 'cleaning', 'container']);
            $table->enum('container_kind', ['S', 'R', 'X'])->default('X');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
