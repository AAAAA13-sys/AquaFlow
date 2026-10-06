<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->enum('payment_status', ['paid', 'unpaid'])->default('paid');
            $table->string('delivery_address', 255)->nullable();
            $table->decimal('balance_after', 12, 2)->nullable();
        });
        DB::table('transactions')->where('payment_method', 'Account')->update(['payment_status' => 'unpaid']);
        Schema::table('demand_forecasts', function (Blueprint $table): void {
            $table->json('model_diagnostics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['payment_status', 'delivery_address', 'balance_after']);
        });
        Schema::table('demand_forecasts', function (Blueprint $table): void {
            $table->dropColumn('model_diagnostics');
        });
    }
};
