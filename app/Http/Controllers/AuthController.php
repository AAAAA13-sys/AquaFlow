<?php

namespace App\Http\Controllers;

use App\Data\LoginResult;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\OwnerLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session authentication.
 *
 * The rules live in AuthService; this controller only maps the outcome onto
 * HTTP (session, status) and shapes the response.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->loginResponse($request, $this->auth->attemptCashier($request->validated(), $request->ip()));
    }

    public function loginOwner(OwnerLoginRequest $request): JsonResponse
    {
        return $this->loginResponse($request, $this->auth->attemptOwner($request->validated(), $request->ip()));
    }

    private function loginResponse(Request $request, LoginResult $result): JsonResponse
    {
        if (! $result->succeeded()) {
            return response()->json(['message' => $result->message], $result->status);
        }

        Auth::login($result->user, false);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($result->user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function session(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'authenticated' => $user !== null,
            'user' => $user ? new UserResource($user) : null,
        ]);
    }
}
