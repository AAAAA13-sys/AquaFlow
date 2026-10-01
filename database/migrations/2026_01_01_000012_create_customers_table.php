<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and the receivables ledger.
 *
 * issued/returned counters track circular container custody per shape;
 * debt_balance is the running Utang balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('address')->default('-');
            $table->string('contact', 50)->default('-')->index();
            $table->unsignedInteger('issued_slim')->default(0);
            $table->unsignedInteger('returned_slim')->default(0);
            $table->unsignedInteger('issued_round')->default(0);
            $table->unsignedInteger('returned_round')->default(0);
            $table->decimal('debt_balance', 10, 2)->default(0);
            $table->unsignedInteger('total_transactions')->default(0);
            $table->date('last_visit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
