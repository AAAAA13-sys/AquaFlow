<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AquaFlow Unified Database Schema Migration.
 *
 * Consolidates all core system tables, RBAC, inventory, catalog, fulfillment,
 * audit logging, approval workflow, and cash reconciliation into a clean,
 * consistent schema definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------------
        // 1. Framework & Infrastructure
        // ---------------------------------------------------------------------
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // ---------------------------------------------------------------------
        // 2. Staff, RBAC & Identity
        // ---------------------------------------------------------------------
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('job_title', 120);
            $table->string('contact_number', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->enum('role', ['super_admin', 'admin', 'cashier', 'rider', 'refiller'])->default('cashier')->index();
            $table->string('name', 100);
            $table->string('job_title', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('email', 255)->nullable()->unique();
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->boolean('is_bootstrap')->default(false);
            $table->string('bootstrap_secret_hash')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
        });

        Schema::create('password_reset_otps', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->index();
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        // ---------------------------------------------------------------------
        // 3. Catalog, Inventory & Suppliers
        // ---------------------------------------------------------------------
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('supplied_items');
            $table->unsignedInteger('lead_time_days')->default(2);
            $table->string('contact', 50)->default('-');
            $table->date('last_delivery')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory', function (Blueprint $table): void {
            $table->id();
            $table->string('item_name', 150)->unique();
            $table->enum('category', ['Consumable', 'Filtration', 'Cleaning', 'Asset']);
            $table->integer('stock_on_hand')->default(0);
            $table->string('unit', 20)->default('pcs');
            $table->unsignedInteger('safety_stock')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->unsignedInteger('target_stock')->default(0);
            $table->unsignedInteger('lead_time_days')->default(2);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->string('name', 100);
            $table->decimal('price', 10, 2)->default(0);
            $table->enum('category', ['refill', 'consumable', 'cleaning', 'container'])->index();
            $table->enum('container_kind', ['S', 'R', 'X'])->default('X');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('sold_at_pos')->default(false);
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory')->nullOnDelete();
            $table->unsignedInteger('inventory_units_per_sale')->default(1);
            $table->boolean('walk_in_only')->default(false);
            $table->timestamps();
        });

        Schema::create('product_consumables', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 30);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedInteger('qty_per_sale');
            $table->timestamps();
            $table->unique(['product_id', 'inventory_item_id']);
        });

        // ---------------------------------------------------------------------
        // 4. Customers & Transactions
        // ---------------------------------------------------------------------
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('address', 255)->default('-');
            $table->string('contact', 50)->default('-')->index();
            $table->unsignedInteger('issued_slim')->default(0);
            $table->unsignedInteger('returned_slim')->default(0);
            $table->unsignedInteger('issued_round')->default(0);
            $table->unsignedInteger('returned_round')->default(0);
            $table->decimal('debt_balance', 10, 2)->default(0);
            $table->decimal('settlement_pending_amount', 10, 2)->default(0);
            $table->timestamp('settlement_pending_at')->nullable();
            $table->unsignedInteger('total_transactions')->default(0);
            $table->date('last_visit')->nullable();
            $table->string('system_key', 30)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table): void {
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
            $table->enum('payment_status', ['paid', 'unpaid'])->default('paid');
            $table->string('delivery_address', 255)->nullable();
            $table->decimal('balance_after', 12, 2)->nullable();
            $table->char('submission_key', 36)->nullable();
            $table->string('submission_hash', 64)->nullable();
            $table->json('submission_result')->nullable();
            $table->timestamps();

            $table->unique(['cashier_id', 'submission_key']);
        });

        Schema::create('transaction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('product_id', 30);
            $table->string('item_name', 100);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('line_total', 10, 2)->default(0);

            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });

        Schema::create('container_custody_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedInteger('slim_out')->default(0);
            $table->unsignedInteger('slim_in')->default(0);
            $table->unsignedInteger('round_out')->default(0);
            $table->unsignedInteger('round_in')->default(0);
            $table->unsignedInteger('deficit_slim')->default(0);
            $table->unsignedInteger('deficit_round')->default(0);
            $table->boolean('damaged_reported')->default(false);
            $table->timestamp('logged_at')->useCurrent();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory')->restrictOnDelete();
            $table->enum('type', ['sale', 'restock', 'adjustment', 'damage']);
            $table->integer('qty');
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('lot_number', 100)->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('reason', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'created_at', 'id']);
        });

        // ---------------------------------------------------------------------
        // 5. Production Queue & Fulfillment
        // ---------------------------------------------------------------------
        Schema::create('production_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 30)->index();
            $table->string('customer_name', 150);
            $table->unsignedInteger('stage')->default(0);
            $table->unsignedInteger('elapsed_minutes')->default(0);
            $table->string('items_description', 100)->default('5 gal');
            $table->enum('order_type', ['Walk-in', 'Delivery'])->default('Walk-in');
            $table->boolean('is_completed')->default(false);
            $table->json('stage_history')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('fulfillment_status', 30)->default('preparing');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->text('delivery_code')->nullable();
            $table->timestamps();

            $table->index(['is_completed', 'created_at']);
        });

        Schema::create('fulfillment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_queue_id')->constrained('production_queue')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->string('request_hash', 64);
            $table->string('action', 30);
            $table->json('details');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'submission_key']);
        });

        Schema::create('delivery_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_queue_id')->unique()->constrained('production_queue')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('rider_id')->constrained('users')->restrictOnDelete();
            $table->string('photo_path', 255);
            $table->string('photo_hash', 64)->unique();
            $table->string('recipient_name', 100);
            $table->unsignedInteger('received_quantity');
            $table->decimal('reported_cash', 10, 2);
            $table->string('collection_note', 500)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('delivery_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_proof_id')->constrained('delivery_proofs')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
        });

        // ---------------------------------------------------------------------
        // 6. Forecasting, Settings & Reconciliation
        // ---------------------------------------------------------------------
        Schema::create('demand_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->string('series_name', 50)->default('Refill Gallons')->index();
            $table->enum('horizon_type', ['Daily', 'Weekly', 'Monthly'])->default('Daily');
            $table->date('forecast_date');
            $table->json('historical_data');
            $table->json('forecasted_data');
            $table->string('model_order', 30)->default('ARIMA(1,1,1)');
            $table->string('method', 40)->default('arima');
            $table->unsignedTinyInteger('differencing')->default(0);
            $table->decimal('aic_score', 8, 2)->default(0);
            $table->decimal('mape_score', 5, 2)->default(0);
            $table->decimal('mae_score', 8, 2)->default(0);
            $table->decimal('rmse_score', 8, 2)->default(0);
            $table->decimal('adf_statistic', 10, 4)->default(0);
            $table->decimal('adf_pvalue', 8, 4)->default(1);
            $table->decimal('ljung_box_pvalue', 8, 4)->default(1);
            $table->decimal('residual_std', 10, 2)->default(0);
            $table->json('model_diagnostics')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('setting_key', 50)->primary();
            $table->text('setting_value');
            $table->string('description')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

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

            $table->unique(['user_id', 'business_date'], 'cash_shifts_user_day_unique');
            $table->index(['business_date', 'closed_at']);
        });

        // ---------------------------------------------------------------------
        // 7. Governance, Approval Workflow, Audit Trail & Defect Reports
        // ---------------------------------------------------------------------
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 60)->nullable();
            $table->json('payload');
            $table->string('reason', 500)->nullable();
            $table->string('state', 20)->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index(['state', 'requested_by']);
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        Schema::create('approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->string('explanation', 500)->nullable();
            $table->uuid('submission_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['actor_id', 'submission_key']);
        });

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

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('actor_name', 100)->nullable();
            $table->string('action', 30)->index();
            $table->string('entity_type', 60);
            $table->string('entity_id', 60)->nullable();
            $table->string('entity_label', 150)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changes')->nullable();
            $table->string('context', 20)->default('direct');
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('defect_reports');
        Schema::dropIfExists('approval_events');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('demand_forecasts');
        Schema::dropIfExists('delivery_remittances');
        Schema::dropIfExists('delivery_proofs');
        Schema::dropIfExists('fulfillment_events');
        Schema::dropIfExists('production_queue');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('container_custody_logs');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('product_consumables');
        Schema::dropIfExists('products');
        Schema::dropIfExists('inventory');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('password_reset_otps');
        Schema::dropIfExists('users');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
    }
};
