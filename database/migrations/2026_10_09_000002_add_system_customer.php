<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('system_key', 30)->nullable()->unique();
        });
        // An upgrade creates its own identity; a customer's display name is not proof.
        if (DB::table('users')->exists()) {
            DB::table('customers')->insert([
                'system_key' => 'walk-in', 'name' => 'Walk-in Guest',
                'address' => '-', 'contact' => '-', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve any sales referencing the guest when rolling back schema.
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('system_key'));
    }
};
