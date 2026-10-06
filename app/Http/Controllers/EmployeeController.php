<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['employees' => EmployeeResource::collection(Employee::orderByDesc('id')->get())]);
    }

    public function store(EmployeeRequest $request, EmployeeService $service): JsonResponse
    {
        return response()->json(['employee' => new EmployeeResource($service->create($request->validated()))], 201);
    }

    public function update(EmployeeRequest $request, Employee $employee, EmployeeService $service): JsonResponse
    {
        return response()->json(['employee' => new EmployeeResource($service->update($employee, $request->validated()))]);
    }

    public function destroy(Employee $employee, EmployeeService $service): JsonResponse
    {
        $service->delete($employee);

        return response()->json(['ok' => true]);
    }
}
