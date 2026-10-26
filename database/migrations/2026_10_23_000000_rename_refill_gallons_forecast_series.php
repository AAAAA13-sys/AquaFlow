<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The primary series is refill jugs, not gallons.
 *
 * A POS quantity is one five-gallon jug; calling it a gallon mixed the unit
 * into every filter/safety-stock calculation. Rename the already-stored rows
 * so an upgraded station keeps its history under the corrected name.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('demand_forecasts')
            ->where('series_name', 'Refill Gallons')
            ->update(['series_name' => 'Refill Jugs']);
    }

    public function down(): void
    {
        DB::table('demand_forecasts')
            ->where('series_name', 'Refill Jugs')
            ->update(['series_name' => 'Refill Gallons']);
    }
};
