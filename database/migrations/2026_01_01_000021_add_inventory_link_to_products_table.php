<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a sellable product to the inventory row it consumes, records how many
 * stock units one sale consumes, and marks items that only make sense for
 * walk-in customers.
 *
 * Under the Zero Station-Owned Jugs rule a brand-new jug is ordinary
 * merchandise: selling one depletes jug stock, exactly like a pack of caps.
 * A refill consumes no container because the jug is the customer's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('sold_at_pos')
                ->constrained('inventory')
                ->nullOnDelete();

            // One "Non-Spill Caps Pack 50" consumes 50 pieces of stock.
            $table->unsignedInteger('inventory_units_per_sale')->default(1)->after('inventory_item_id');

            // Brand-new jugs are counter sales: they cannot ride a delivery.
            $table->boolean('walk_in_only')->default(false)->after('inventory_units_per_sale');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropColumn(['inventory_units_per_sale', 'walk_in_only']);
        });
    }
};
