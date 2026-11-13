<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An explicit default prevents older MariaDB installations from adding
        // ON UPDATE CURRENT_TIMESTAMP to the first non-null TIMESTAMP column.
        // Existing expiry/opening values are preserved; future row updates must
        // never renew/burn an OTP or rewrite the original drawer opening time.
        Schema::table('password_reset_otps', function (Blueprint $table): void {
            $table->timestamp('expires_at')->useCurrent()->change();
        });
        Schema::table('cash_shifts', function (Blueprint $table): void {
            $table->timestamp('opened_at')->useCurrent()->change();
        });
    }

    public function down(): void
    {
        // Keep this data-integrity correction on rollback. Reintroducing an
        // implicit ON UPDATE clause would corrupt historical timestamps again.
    }
};
