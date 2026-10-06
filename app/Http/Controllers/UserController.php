<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Users and access (owner only).
 */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'users' => UserResource::collection(
                User::query()->orderBy('id')->get()
            ),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($data['role'] === User::ROLE_ADMIN && empty($data['pin'])) {
            throw ValidationException::withMessages([
                'pin' => 'An Owner PIN is required for admin accounts.',
            ]);
        }

        $user = User::query()->create([
            'username' => $data['username'],
            'password' => $data['password'],
            'role' => $data['role'],
            'name' => $data['name'],
            'pin' => ! empty($data['pin']) ? Hash::make($data['pin']) : null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['user' => new UserResource($user)], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('role', $data)) {
            if ($user->id === $request->user()->id && $data['role'] !== $user->role) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot change your own role.',
                ]);
            }
            if ($data['role'] === User::ROLE_ADMIN && empty($data['pin'] ?? null) && empty($user->pin)) {
                throw ValidationException::withMessages([
                    'pin' => 'An Owner PIN is required for admin accounts.',
                ]);
            }
            $user->role = $data['role'];
            if ($user->role !== User::ROLE_ADMIN) {
                $user->pin = null;
            }
        }

        if (! empty($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        if (array_key_exists('pin', $data)) {
            $user->pin = ! empty($data['pin']) ? Hash::make($data['pin']) : null;
        }

        if (array_key_exists('is_active', $data)) {
            if ($user->id === $request->user()->id && ! $data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own account.',
                ]);
            }
            $user->is_active = (bool) $data['is_active'];
        }

        $user->save();

        return response()->json(['user' => new UserResource($user->refresh())]);
    }

    public function destroy(User $user): JsonResponse
    {
        $actor = request()->user();

        if ($actor !== null && $user->id === $actor->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        if ($user->isAdmin()) {
            $otherAdmins = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherAdmins === 0) {
                throw ValidationException::withMessages([
                    'user' => 'At least one active owner account must remain.',
                ]);
            }
        }

        $user->delete();

        return response()->json(['ok' => true]);
    }
}
