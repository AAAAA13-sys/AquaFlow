<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-day cash reconciliation.
 *
 * A station proves its money by counting the drawer and comparing it with what
 * the sales record says should be there. This table stores one row per till
 * session: the float placed in at open, the counted cash and expected cash at
 * close, and the difference between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('opening_float', 10, 2)->default(0);
            $table->decimal('expected_cash', 10, 2)->default(0);
            $table->decimal('counted_cash', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable();
            $table->decimal('total_sales', 10, 2)->default(0);
            $table->unsignedInteger('cash_sales_count')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // One open session per cashier per day keeps the float from being
            // recorded twice for the same drawer.
            $table->unique(['user_id', 'business_date'], 'cash_shifts_user_day_unique');
            $table->index(['business_date', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shifts');
    }
};