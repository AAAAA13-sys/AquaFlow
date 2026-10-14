<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->json('recovery_codes')->nullable();
        });
        Schema::create('owner_login_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_hash', 64);
            $table->string('code_hash');
            $table->string('email');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_login_challenges');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['email', 'email_verified_at', 'recovery_codes']));
    }
};
