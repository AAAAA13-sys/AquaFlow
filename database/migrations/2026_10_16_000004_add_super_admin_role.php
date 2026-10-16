<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin','admin','cashier','rider','refiller') NOT NULL DEFAULT 'cashier'");
        // Every pre-upgrade admin was an owner; preserve that authority and identity.
        DB::table('users')->where('role', 'admin')->update(['role' => 'super_admin']);
    }

    public function down(): void
    {
        if (DB::table('users')->where('role', 'super_admin')->exists()) {
            throw new RuntimeException('Reassign Super Admin accounts before rolling back the owner hierarchy.');
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','cashier','rider','refiller') NOT NULL DEFAULT 'cashier'");
    }
};
