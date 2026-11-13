<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('po_number', 20)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->enum('status', ['draft', 'ordered', 'partially_received', 'received', 'cancelled'])
                ->default('draft')->index();
            $table->date('expected_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedInteger('qty_ordered');
            $table->unsignedInteger('qty_received')->default(0);
            $table->decimal('unit_cost', 12, 4);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'inventory_item_id']);
        });

        Schema::create('purchase_order_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('submission_key', 36);
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'submission_key']);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->after('transaction_id')
                ->constrained('purchase_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
        Schema::dropIfExists('purchase_order_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
