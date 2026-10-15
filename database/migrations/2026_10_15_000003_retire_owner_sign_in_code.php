<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the owner sign-in code.
 *
 * Owner sign-in was email + password + a six-digit code emailed to the address
 * on file. The code is removed: the portal is protected by server-side role
 * middleware, so the emailed step added a mail-delivery dependency without
 * adding authorisation, and it made the owner unable to sign in on a station
 * with no working mailbox.
 *
 * Owners now sign in with the username and password they chose at setup, the
 * same credentials every other account uses. The portal decides where a
 * successful sign-in lands; the role decides whether it is allowed.
 *
 * Removed here rather than in the migration that created them, because those
 * migrations have shipped. `down()` is deliberately not a full inverse: the
 * columns are recreated empty, so a rollback restores the shape but not any
 * previously issued codes. Those were single-use and five-minute lived, so
 * nothing is lost that was still redeemable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'recovery_codes']);
        });

        Schema::dropIfExists('owner_login_challenges');
    }

    public function down(): void
    {
        Schema::create('owner_login_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_hash', 64);
            $table->string('code_hash');
            $table->string('email');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->index(['user_id', 'consumed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->text('recovery_codes')->nullable()->after('email_verified_at');
        });
    }
};
