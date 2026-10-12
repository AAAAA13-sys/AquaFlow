<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the published bootstrap credentials with a per-install secret.
 *
 * The first-run gate used to be a fixed email, password and code compiled into
 * AdminSetupService and printed in the installer's Getting Started guide, so
 * every deployment shared one publicly known secret. The secret is now generated
 * per installation, shown once on the console by Setup, and stored only as a
 * bcrypt hash on the temporary bootstrap account.
 *
 * Only bcrypt hashes live here; the plaintext exists in the console output and
 * nowhere else. Re-running Setup issues a fresh secret while setup is unfinished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bootstrap_secret_hash')->nullable()->after('is_bootstrap')
                ->comment('bcrypt hash of the one-time first-run setup secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bootstrap_secret_hash');
        });
    }
};
