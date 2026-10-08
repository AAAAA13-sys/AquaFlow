<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(9, 0));
        $this->employee = Employee::create(['name' => 'Ana Santos', 'job_title' => 'Refill operator', 'is_active' => true]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
    }

    private function punch(string $action)
    {
        return $this->postJson('/api/attendance/employees/'.$this->employee->id.'/'.$action, ['reason' => 'Employee reported a schedule exception']);
    }

    private function correction(array $data = [])
    {
        return $this->postJson('/api/attendance/employees/'.$this->employee->id.'/record', array_merge(['attendance_date' => '2026-10-08', 'type' => 'present', 'time_in' => '2026-10-08T08:05', 'time_out' => null, 'reason' => 'Missed arrival entry', 'expected_version' => 0], $data));
    }

    public function test_daily_punches_are_idempotent_and_audited(): void
    {
        $this->getJson('/api/attendance')->assertOk()->assertJsonPath('rows.0.status', 'not_recorded');
        $this->punch('time-in')->assertOk()->assertJsonPath('row.status', 'late')->assertJsonPath('row.progress', 'checked_in');
        $first = DB::table('employee_attendances')->first();
        $this->travel(10)->minutes();
        $this->punch('time-in')->assertOk();
        $this->assertDatabaseCount('employee_attendances', 1);
        $this->assertDatabaseCount('employee_attendance_changes', 1);
        $this->assertSame($first->time_in, DB::table('employee_attendances')->value('time_in'));
        $this->punch('time-out')->assertOk()->assertJsonPath('row.status', 'late')->assertJsonPath('row.progress', 'completed');
        $this->punch('time-out')->assertOk();
        $this->assertDatabaseCount('employee_attendance_changes', 2);
    }

    public function test_cashier_cannot_edit_or_read_history_and_guests_are_denied(): void
    {
        $this->correction()->assertNotFound();
        $this->getJson('/api/attendance?history=1')->assertForbidden();
        $this->getJson('/api/attendance?date=2026-10-07')->assertForbidden();
        $this->getJson('/api/attendance/employees/'.$this->employee->id.'/changes')->assertForbidden();
        $this->get('/admin/attendance')->assertRedirect('/cashier');
        $this->get('/cashier/attendance')->assertOk()->assertSee('Staff Attendance');
        auth()->logout();
        $this->getJson('/api/attendance')->assertUnauthorized();
    }

    public function test_missing_departure_is_not_invented_and_inactive_employee_can_finish(): void
    {
        $this->punch('time-out')->assertUnprocessable();
        $this->punch('time-in')->assertOk();
        $this->employee->update(['is_active' => false]);
        $this->punch('time-out')->assertOk();
        $this->travel(1)->days();
        $this->punch('time-in')->assertUnprocessable();
        $this->employee->update(['is_active' => true]);
        $this->punch('time-in')->assertOk();
        $this->travel(1)->days();
        $this->punch('time-out')->assertUnprocessable();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson('/api/attendance?history=1&status=late')->assertOk()->assertJsonPath('rows.0.progress', 'needs_review')->assertJsonPath('rows.0.time_out', null);
    }

    public function test_admin_can_read_audit_but_cannot_change_or_punch_attendance(): void
    {
        $this->punch('time-in')->assertOk();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->correction()->assertNotFound();
        $this->punch('time-in')->assertForbidden();
        $this->punch('time-out')->assertForbidden();
        $this->getJson('/api/attendance/employees/'.$this->employee->id.'/changes')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('employee_attendance_changes', 1);
    }

    public function test_soft_deleted_employee_history_survives_and_open_entry_can_finish(): void
    {
        $this->punch('time-in')->assertOk();
        $this->employee->delete();
        $this->getJson('/api/attendance?search=Ana')->assertOk()->assertJsonCount(1, 'rows');
        $this->punch('time-out')->assertOk();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson('/api/attendance?history=1&search=Ana')->assertOk()->assertJsonPath('rows.0.employee_name', 'Ana Santos');
        $this->get('/admin/attendance')->assertOk()->assertSee('Attendance');
    }

    public function test_cashier_cannot_supply_a_punch_timestamp_and_date_uses_station_timezone(): void
    {
        $this->postJson('/api/attendance/employees/'.$this->employee->id.'/time-in', ['time_in' => '2026-10-07T08:00'])->assertUnprocessable();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(23, 59));
        $this->punch('time-in')->assertOk();
        $this->travel(2)->minutes();
        $this->punch('time-in')->assertOk();
        $this->assertDatabaseHas('employee_attendances', ['attendance_date' => '2026-10-08', 'time_out' => null]);
        $this->assertDatabaseHas('employee_attendances', ['attendance_date' => '2026-10-09']);
    }

    public function test_admin_history_filters_and_absence_do_not_leak_private_notes_to_cashier(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $attendance = EmployeeAttendance::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-08', 'type' => 'absent', 'version' => 1]);
        DB::table('employee_attendance_changes')->insert(['employee_attendance_id' => $attendance->id, 'user_id' => auth()->id(), 'action' => 'correction', 'reason' => 'Private owner note', 'after' => json_encode($attendance->getAttributes()), 'created_at' => now()]);
        $this->getJson('/api/attendance?status=absent&search=Ana')->assertOk()->assertJsonCount(1, 'rows');
        $this->getJson('/api/attendance?status=completed')->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/attendance?history=1&order=oldest')->assertOk()->assertJsonCount(1, 'rows');
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/attendance')->assertOk()->assertDontSee('Private owner note')->assertDontSee('contact_number');
        $this->punch('time-in')->assertUnprocessable();
        $this->punch('time-out')->assertUnprocessable();
    }

    public function test_attendance_list_is_paginated_and_unknown_employee_is_not_found(): void
    {
        for ($i = 0; $i < 26; $i++) {
            Employee::create(['name' => 'Worker '.$i, 'job_title' => 'Operator', 'is_active' => true]);
        }
        $this->getJson('/api/attendance')->assertOk()->assertJsonCount(25, 'rows')->assertJsonPath('meta.total', Employee::count());
        $this->getJson('/api/attendance?page=2')->assertOk()->assertJsonCount(Employee::count() - 25, 'rows');
        $this->postJson('/api/attendance/employees/999999/time-in')->assertNotFound();
    }

    public function test_schedule_exceptions_require_reasons_and_retry_preserves_the_original_audit(): void
    {
        $url = '/api/attendance/employees/'.$this->employee->id;
        $this->travelTo(now()->setTime(7, 30));
        $this->postJson($url.'/time-in')->assertOk();
        $this->travelTo(now()->setTime(7, 0));
        $this->postJson($url.'/time-out', ['reason' => 'Leaving for an emergency'])->assertUnprocessable();
        $this->travelTo(now()->setTime(13, 0));
        $this->postJson($url.'/time-out')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('employee_attendance_changes', 1);
        $this->postJson($url.'/time-out', ['reason' => 'Medical appointment'])->assertOk();
        $this->postJson($url.'/time-out')->assertOk();
        $this->assertDatabaseCount('employee_attendance_changes', 2);
        $this->assertDatabaseHas('employee_attendance_changes', ['action' => 'time-out', 'reason' => 'Medical appointment']);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson($url.'/changes')->assertOk()->assertJsonPath('data.0.reason', 'Medical appointment');
    }

    public function test_on_schedule_punches_need_no_reason_but_late_arrivals_do(): void
    {
        $url = '/api/attendance/employees/'.$this->employee->id;
        $this->travelTo(now()->setTime(8, 0));
        $this->postJson($url.'/time-in')->assertOk();
        $this->travelTo(now()->setTime(17, 0));
        $this->postJson($url.'/time-out')->assertOk();
        $this->travel(1)->days();
        $this->travelTo(now()->setTime(8, 1));
        $this->postJson($url.'/time-in')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url.'/time-in', ['reason' => str_repeat('x', 501)])->assertUnprocessable();
        $this->postJson($url.'/time-in', ['reason' => 'Delayed by traffic'])->assertOk();
    }
}
