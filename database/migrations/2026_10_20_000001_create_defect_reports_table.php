<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff report physical stock they found damaged or defective. An admin or
     * super admin then approves (writing the units off stock) or rejects.
     *
     * Both user foreign keys preserve the report as historical evidence: the
     * reporter key is restricted so an account with reports cannot be deleted
     * out from under them, and the resolver key is nullable and set null when
     * that admin account goes away. The inventory key is restrictOnDelete,
     * matching stock_movements and product_consumables — and inventory rows are
     * retired (retired_at) rather than hard-deleted anyway.
     */
    public function up(): void
    {
        Schema::create('defect_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason', 255);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('defect_reports');
    }
};
