<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendancePunchRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeePortalController extends Controller
{
    public function attendance(Request $request, AttendanceService $service): JsonResponse
    {
        $user = $request->user();
        $employee = $this->resolveEmployee($user);
        $attendance = EmployeeAttendance::with(['recorderIn', 'recorderOut'])->where('employee_id', $employee->id)->whereDate('attendance_date', today())->first();

        return response()->json(['row' => new AttendanceResource($service->row($employee, $attendance, today()->toDateString())), 'server_time' => now()->toISOString()]);
    }

    public function punch(AttendancePunchRequest $request, string $action, AttendanceService $service): JsonResponse
    {
        $user = $request->user();
        $employee = $this->resolveEmployee($user);

        return response()->json(['row' => new AttendanceResource($service->punch($employee->id, $action, $user, $request->validated('reason')))]);
    }

    private function resolveEmployee(User $user): Employee
    {
        if ($user->employee) {
            return $user->employee;
        }

        $employee = Employee::where('name', $user->name)->first();
        if (! $employee) {
            $employee = Employee::create([
                'name' => $user->name,
                'job_title' => $user->role === 'cashier' ? 'Cashier' : ucfirst($user->role),
                'is_active' => true,
            ]);
        }

        $user->forceFill(['employee_id' => $employee->id])->save();

        return $employee;
    }
}
