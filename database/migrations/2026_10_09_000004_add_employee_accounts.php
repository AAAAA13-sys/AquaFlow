<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','cashier','rider','refiller') NOT NULL DEFAULT 'cashier'");
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereIn('role', ['rider', 'refiller'])->exists()) {
            throw new RuntimeException('Reassign employee accounts before rolling back roles.');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
        });
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier'");
    }
};
