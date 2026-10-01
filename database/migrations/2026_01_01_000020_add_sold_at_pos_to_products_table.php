<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which catalog items the cashier sells at the till.
 *
 * The POS offers four items: Slim and Round refills and the two new-jug
 * deposits. Caps, seals and cleaning supplies are station inventory that the
 * owner restocks from the admin side; they are never rung up as a sale (though
 * caps and seals are still auto-deducted per refilled gallon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sold_at_pos')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sold_at_pos');
        });
    }
};
