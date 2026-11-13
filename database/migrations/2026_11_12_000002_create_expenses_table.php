<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->date('spent_on')->index();
            $table->enum('category', ['utilities', 'rent', 'salaries', 'supplies', 'maintenance', 'transport', 'permits', 'other']);
            $table->decimal('amount', 12, 2);
            $table->string('payee', 100)->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->char('submission_key', 36);
            // Detects a retry that reuses a key with different content.
            $table->char('request_hash', 64);
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['recorded_by', 'submission_key']);
            $table->index(['category', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
