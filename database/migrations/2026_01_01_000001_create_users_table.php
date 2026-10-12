<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users and access control (RBAC).
 *
 * Credentials are bcrypt hashes. `pin` was the reusable Owner PIN; it is created
 * here because fresh installs run this migration first, and dropped again by
 * 2026_10_12_000001_drop_pin_from_users once owner sign-in moved to email OTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->enum('role', ['cashier', 'admin'])->default('cashier')->index();
            $table->string('name', 100);
            $table->string('pin')->nullable()->comment('bcrypt hash of the Owner PIN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
