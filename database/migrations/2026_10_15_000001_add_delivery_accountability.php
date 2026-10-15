<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_queue', function (Blueprint $table): void {
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->text('delivery_code')->nullable();
        });
        Schema::create('delivery_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_queue_id')->unique()->constrained('production_queue')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->string('photo_path');
            $table->string('photo_hash', 64)->unique();
            $table->string('recipient_name', 100);
            $table->unsignedInteger('received_quantity');
            $table->decimal('reported_cash', 10, 2);
            $table->string('collection_note', 500)->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('delivery_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_proof_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_remittances');
        Schema::dropIfExists('delivery_proofs');
        Schema::table('production_queue', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('handed_over_by');
            $table->dropColumn(['handed_over_at', 'delivery_code']);
        });
    }
};
