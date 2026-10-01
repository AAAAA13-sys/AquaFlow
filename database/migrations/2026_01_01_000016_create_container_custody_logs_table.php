<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circular container custody audit log (Slim/Round out vs returned).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_custody_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedInteger('slim_out')->default(0);
            $table->unsignedInteger('slim_in')->default(0);
            $table->unsignedInteger('round_out')->default(0);
            $table->unsignedInteger('round_in')->default(0);
            $table->unsignedInteger('deficit_slim')->default(0);
            $table->unsignedInteger('deficit_round')->default(0);
            $table->boolean('damaged_reported')->default(false);
            $table->timestamp('logged_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_custody_logs');
    }
};
