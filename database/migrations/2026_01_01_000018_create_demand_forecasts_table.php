<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARIMA demand forecast log (written by the Tier 3 Python service).
 *
 * Stores the series, the projection and the model diagnostics so the dashboard
 * can report accuracy rather than a bare number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->string('series_name', 50)->default('Refill Gallons');
            $table->enum('horizon_type', ['Daily', 'Weekly', 'Monthly'])->default('Daily');
            $table->date('forecast_date');
            $table->json('historical_data');
            $table->json('forecasted_data');
            $table->string('model_order', 30)->default('ARIMA(1,1,1)');
            $table->string('method', 40)->default('arima');
            $table->unsignedTinyInteger('differencing')->default(0);
            $table->decimal('aic_score', 8, 2)->default(0);
            $table->decimal('mape_score', 5, 2)->default(0);
            $table->decimal('mae_score', 8, 2)->default(0);
            $table->decimal('rmse_score', 8, 2)->default(0);
            $table->decimal('adf_statistic', 10, 4)->default(0);
            $table->decimal('adf_pvalue', 8, 4)->default(1);
            $table->decimal('ljung_box_pvalue', 8, 4)->default(1);
            $table->decimal('residual_std', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['series_name', 'forecast_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
