<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->whereIn('id', ['newS', 'newR'])->update(['walk_in_only' => false]);
    }

    public function down(): void
    {
        DB::table('products')->whereIn('id', ['newS', 'newR'])->update(['walk_in_only' => true]);
    }
};
