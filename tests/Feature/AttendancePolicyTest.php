<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_rewrite_attendance_and_late_remains_late_after_departure(): void
    {
        $this->seed();
        $this->travelTo(now()->setTime(9, 0));
        $employee = Employee::firstOrFail();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $url = '/api/attendance/employees/'.$employee->id;
        $this->postJson($url.'/time-in', ['reason' => 'Traffic delay'])->assertOk()->assertJsonPath('row.status', 'late');
        $this->travelTo(now()->setTime(18, 0));
        $this->postJson($url.'/time-out')->assertOk()->assertJsonPath('row.status', 'late')->assertJsonPath('row.progress', 'completed');
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $before = EmployeeAttendance::firstOrFail()->getAttributes();
        $this->postJson($url.'/record', ['attendance_date' => now()->toDateString(), 'type' => 'present', 'time_in' => now()->setTime(8, 0)->format('Y-m-d\TH:i'), 'reason' => 'Change arrival', 'expected_version' => 2])->assertNotFound();
        $this->assertSame($before, EmployeeAttendance::firstOrFail()->getAttributes());
        $this->getJson('/api/attendance?status=late')->assertOk()->assertJsonCount(1, 'rows');
        $this->get('/admin/attendance')->assertOk()->assertDontSee('Review / correct')->assertSee('Read-only');
    }

    public function test_no_arrival_is_pending_until_closing_then_absent_without_creating_fake_punches(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->travelTo(now()->setTime(16, 59));
        $this->getJson('/api/attendance?status=absent')->assertOk()->assertJsonCount(0, 'rows');
        $this->travelTo(now()->setTime(17, 0));
        $this->getJson('/api/attendance?status=absent')->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('rows.0.status', 'absent');
        $this->getJson('/api/attendance?status=not_recorded')->assertOk()->assertJsonCount(0, 'rows');
        $this->assertDatabaseCount('employee_attendances', 0);
    }
}
