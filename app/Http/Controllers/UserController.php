<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

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
        $user = app(UserService::class)->create($request->validated());

        return response()->json(['user' => new UserResource($user)], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = app(UserService::class)->update($user, $request->validated(), $request->user());

        return response()->json(['user' => new UserResource($user)]);
    }

    public function destroy(User $user): JsonResponse
    {
        app(UserService::class)->delete($user, request()->user());

        return response()->json(['ok' => true]);
    }
}
