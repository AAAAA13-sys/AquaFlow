<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_queue', fn (Blueprint $table) => $table->json('stage_history')->nullable());
    }

    public function down(): void
    {
        Schema::table('production_queue', fn (Blueprint $table) => $table->dropColumn('stage_history'));
    }
};
