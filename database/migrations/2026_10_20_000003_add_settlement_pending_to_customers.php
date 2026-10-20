<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risk fix: a debt settlement no longer reduces the balance at the counter.
 *
 * The cashier records the money received and the manager approves it, and only
 * approval posts the ledger entry. That leaves a window where the customer has
 * physically paid but `debt_balance` still shows the full amount - which reads
 * to every other screen as "this customer still owes", and could lead to the
 * same money being collected twice.
 *
 * These two columns make that window visible instead of silent:
 *
 *   settlement_pending_amount  how much cash has been handed over but not yet
 *                              approved for this customer
 *   settlement_pending_at      when the oldest unapproved collection arrived
 *
 * Availability checks elsewhere subtract the pending amount, so the counter
 * cannot collect the same pesos again while a request is waiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('settlement_pending_amount', 10, 2)->default(0)->after('debt_balance');
            $table->timestamp('settlement_pending_at')->nullable()->after('settlement_pending_amount');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['settlement_pending_amount', 'settlement_pending_at']);
        });
    }
};
