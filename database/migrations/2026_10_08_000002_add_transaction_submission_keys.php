<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $t): void {
            $t->uuid('submission_key')->nullable();
            $t->string('submission_hash', 64)->nullable();
            $t->json('submission_result')->nullable();
            $t->unique(['cashier_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $t): void {
            $t->dropUnique(['cashier_id', 'submission_key']);
            $t->dropColumn(['submission_key', 'submission_hash', 'submission_result']);
        });
    }
};
