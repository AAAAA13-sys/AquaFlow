<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceIndexRequest;
use App\Http\Requests\AttendancePunchRequest;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function index(AttendanceIndexRequest $request, AttendanceService $service): JsonResponse
    {
        $rows = $service->listing($request->validated());

        return response()->json(['rows' => AttendanceResource::collection($rows), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()], 'today' => now()->toDateString(), 'timezone' => config('app.timezone')]);
    }

    public function punch(AttendancePunchRequest $request, int $employeeId, string $action, AttendanceService $service): JsonResponse
    {
        return response()->json(['row' => new AttendanceResource($service->punch($employeeId, $action, $request->user(), $request->validated()['reason'] ?? null))]);
    }

    public function changes(int $employeeId, AttendanceService $service): JsonResponse
    {
        return response()->json($service->changes($employeeId));
    }
}
