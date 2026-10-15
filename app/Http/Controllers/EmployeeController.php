<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['employees' => EmployeeResource::collection(Employee::orderByDesc('id')->get())]);
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        return response()->json(['employee' => new EmployeeResource(Employee::create($request->validated()))], 201);
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee->update($request->validated());

        return response()->json(['employee' => new EmployeeResource($employee->refresh())]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();

        return response()->json(['ok' => true]);
    }
}
