<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_attendances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('attendance_date');
            $t->string('type', 16)->default('present');
            $t->dateTime('time_in')->nullable();
            $t->dateTime('time_out')->nullable();
            $t->foreignId('time_in_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('time_out_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['employee_id', 'attendance_date']);
            $t->index(['attendance_date', 'id']);
        });
        Schema::create('employee_attendance_changes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('employee_attendance_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 32);
            $t->string('reason', 500)->nullable();
            $t->json('before')->nullable();
            $t->json('after');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_changes');
        Schema::dropIfExists('employee_attendances');
    }
};
