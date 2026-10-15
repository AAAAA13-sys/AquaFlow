<?php

use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable();
        });
        (new CatalogSeeder)->retireLegacyInventory();
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table): void {
            $table->dropColumn('retired_at');
        });
    }
};
