<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
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
                User::query()->where('is_active', true)->orderBy('id')->get()
            ),
        ]);
    }
}
