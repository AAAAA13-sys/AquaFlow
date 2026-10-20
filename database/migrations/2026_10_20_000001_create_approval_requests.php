<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maker-checker approval requests.
 *
 * A manager (or cashier) proposes a change; a more senior account accepts or
 * rejects it. The proposal carries the exact validated field set the maker
 * submitted, so approval replays a known shape rather than trusting a client to
 * describe the change at decision time.
 *
 * Nothing here writes domain data. `approval_requests` is the proposal and its
 * state; `approval_events` is the append-only review history. A terminal request
 * is never edited or deleted - a resubmission links back through
 * `superseded_by` so the original decision stays as evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();

            // A fixed, server-owned vocabulary: `ApprovalTypes` maps each value
            // to the rank that may propose it, the rank that may decide it and
            // the single applier allowed to touch domain data.
            $table->string('type', 40)->index();

            // Null for a create: there is no row to point at yet.
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 60)->nullable();

            // The maker's proposed values, already through their Form Request.
            $table->json('payload');
            $table->string('reason', 500)->nullable();

            $table->string('state', 20)->default('pending')->index();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            // Set when this request replaced an earlier terminal one.
            $table->foreignId('superseded_by')->nullable()->constrained('approval_requests')->restrictOnDelete();

            // Concurrent reviewers are rejected on a stale version.
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

            // Role at the time of the action, so a later promotion or
            // deactivation cannot rewrite who was entitled to decide.
            $table->string('actor_role', 20);

            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->string('explanation', 500)->nullable();

            // Idempotency: one terminal decision per key per actor.
            $table->uuid('submission_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->json('result')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['actor_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_events');
        Schema::dropIfExists('approval_requests');
    }
};
