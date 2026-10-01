<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS transaction audit log.
 *
 * Amounts are stored as computed by the server, never as supplied by a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->enum('order_type', ['Walk-in', 'Delivery', 'Debt Payment'])->default('Walk-in')->index();
            $table->string('gallons_volume', 30)->default('0 gal');
            $table->decimal('subtotal_amount', 10, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('auto_discount', 10, 2)->default(0);
            $table->decimal('manual_discount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->enum('payment_method', ['Cash', 'GCash', 'Account'])->default('Cash');
            $table->decimal('cash_tendered', 10, 2)->default(0);
            $table->decimal('cash_change', 10, 2)->default(0);
            $table->date('transaction_date')->index();
            $table->time('transaction_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
