<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('employee');

        return response()->json([
            'user' => new UserResource($user),
            'employee' => $user->employee ? [
                'id' => $user->employee->id,
                'name' => $user->employee->name,
                'job_title' => $user->employee->job_title,
                'is_active' => (bool) $user->employee->is_active,
            ] : null,
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if (! empty($validated['new_password'])) {
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }
            $user->password = $validated['new_password'];
            $user->must_change_password = false;
        }

        if ($user->name !== $validated['name']) {
            $user->name = $validated['name'];
        }

        DB::transaction(function () use ($user): void {
            if ($user->isDirty('name') && $user->employee_id && $user->employee) {
                $user->employee->update(['name' => $user->name]);
            }
            $user->save();
        });

        return response()->json([
            'ok' => true,
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->refresh()->load('employee')),
        ]);
    }
}
