<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_queue', function (Blueprint $table): void {
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('fulfillment_status', 30)->default('preparing');
            $table->unsignedInteger('version')->default(0);
        });
        DB::table('production_queue')->where('is_completed', true)->update(['fulfillment_status' => 'delivered']);
        DB::statement('UPDATE production_queue q JOIN transactions t ON t.receipt_number = q.receipt_number SET q.transaction_id = t.id');
        Schema::create('fulfillment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_queue_id')->constrained('production_queue')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_key');
            $table->string('request_hash', 64);
            $table->string('action', 30);
            $table->json('details');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_events');
        Schema::table('production_queue', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transaction_id');
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn(['fulfillment_status', 'version']);
        });
    }
};
