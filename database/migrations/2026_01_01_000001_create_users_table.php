<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users and access control (RBAC).
 *
 * Credentials are bcrypt hashes: the password column holds the account
 * password and `pin` holds the Owner PIN (never stored in plain text).
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
