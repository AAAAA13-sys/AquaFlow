<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Query-change audit trail.
 *
 * Records what changed, who changed it and when, for the entities the station
 * treats as sensitive. Rank isolation is enforced in `AuditLogService`, not
 * here: a manager reads the changes made by accounts below them, the owner
 * reads everything.
 *
 * `actor_role` and `entity_label` are snapshots. A role change, a rename or a
 * deactivation later on must not rewrite what the history says happened.
 *
 * Only changed fields are stored, never whole rows, so customer balances and
 * contact details are not duplicated into a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->string('actor_name', 100)->nullable();

            $table->string('action', 30)->index();
            $table->string('entity_type', 60);
            $table->string('entity_id', 60)->nullable();
            $table->string('entity_label', 150)->nullable();

            // Changed fields only: before / after are the differing keys, and
            // `changes` is the precomputed label => [from, to] map the UI reads.
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changes')->nullable();

            // How the change reached the database: a direct senior write, an
            // approved proposal, or a submitted collection.
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
    }
};
