<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('job_title', 120);
            $table->string('contact_number', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('production_queue', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('production_queue', fn (Blueprint $table) => $table->dropColumn('delivered_at'));
        Schema::dropIfExists('employees');
    }
};
