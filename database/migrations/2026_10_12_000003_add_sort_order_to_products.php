<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give till buttons a deterministic order.
 *
 * POS tile order used to live in a hardcoded array in public/js/pos/cart.js
 * (['slim','round','newS','newR']). That cannot survive the owner adding an item
 * through the Till & Prices screen: a new product would have no position, and the
 * client would have no way to know where it belongs. Order now comes from the
 * server, so any product - seeded or newly added - lands predictably.
 *
 * Existing products are backfilled to their current on-screen order, so nothing
 * moves for an installed store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        // Preserve the order the terminal shipped with.
        $current = ['slim' => 10, 'round' => 20, 'newS' => 30, 'newR' => 40];
        foreach ($current as $id => $position) {
            DB::table('products')->where('id', $id)->update(['sort_order' => $position]);
        }

        // Anything else (owner-managed supplies) follows, in a stable order.
        $rest = DB::table('products')
            ->whereNotIn('id', array_keys($current))
            ->orderBy('id')
            ->pluck('id');

        $position = 50;
        foreach ($rest as $id) {
            DB::table('products')->where('id', $id)->update(['sort_order' => $position]);
            $position += 10;
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
