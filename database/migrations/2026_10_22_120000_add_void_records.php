<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->index();
        });
        Schema::create('void_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->string('request_hash', 64);
            $table->string('reason', 500);
            $table->json('cart')->nullable();
            $table->timestamps();
            $table->unique(['requested_by', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('void_records');
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('voided_at'));
    }
};
