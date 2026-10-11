<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendancePunchRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\EmployeeAttendance;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeePortalController extends Controller
{
    public function attendance(Request $request, AttendanceService $service): JsonResponse
    {
        $employee = $request->user()->employee;
        $attendance = EmployeeAttendance::with(['recorderIn', 'recorderOut'])->where('employee_id', $employee->id)->whereDate('attendance_date', today())->first();

        return response()->json(['row' => new AttendanceResource($service->row($employee, $attendance, today()->toDateString())), 'server_time' => now()->toISOString()]);
    }

    public function punch(AttendancePunchRequest $request, string $action, AttendanceService $service): JsonResponse
    {
        return response()->json(['row' => new AttendanceResource($service->punch($request->user()->employee_id, $action, $request->user(), $request->validated('reason')))]);
    }
}
