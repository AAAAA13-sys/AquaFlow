<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the staff account a job title.
 *
 * Staff were modelled twice: an `employees` row holding name, job title and
 * employment status, and a `users` row holding the login, role and password.
 * The owner had to maintain the same person in two screens, so the employee
 * directory and the staff screen could disagree.
 *
 * The staff screen is the one that carries access control, so it becomes the
 * single place an owner edits a person. `job_title` is the one field it did not
 * already have, so it is added here and existing values are copied across from
 * the employee directory.
 *
 * The employee directory itself stays. Delivery riders are still linked to an
 * employee row, and that linkage is what the fulfillment records attribute work
 * to. It simply stops being a screen the owner has to maintain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title', 120)->nullable()->after('name');
        });

        // Preserve any job title already recorded against a staff account.
        Employee::query()
            ->whereNotNull('job_title')
            ->orderBy('id')
            ->each(function (Employee $employee): void {
                User::query()
                    ->where('employee_id', $employee->id)
                    ->update(['job_title' => $employee->job_title]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
