<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the Owner PIN.
 *
 * Owner authentication is email + password + emailed one-time code. The reusable
 * PIN was dropped from the login flow first (OwnerLoginRequest marks the field
 * `prohibited`, and tests assert that neither a correct nor an incorrect PIN
 * grants a session); this removes the last of it from storage.
 *
 * The column is dropped rather than nulled: nothing reads it any more, and a
 * dormant credential column is exactly the kind of thing that gets quietly
 * reintroduced later. Values are deleted with it - take a backup first if a
 * store still has one (Setup ships Backup.cmd).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('name')
                ->comment('bcrypt hash of the retired Owner PIN');
        });
    }
};
