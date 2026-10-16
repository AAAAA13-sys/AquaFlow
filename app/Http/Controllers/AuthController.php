<?php

namespace App\Http\Controllers;

use App\Data\LoginResult;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Http\Requests\ResetPasswordWithOtpRequest;
use App\Http\Requests\SendPasswordResetOtpRequest;
use App\Http\Requests\VerifyPasswordResetOtpRequest;
use App\Services\PasswordResetService;

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
        private readonly PasswordResetService $passwordReset,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->loginResponse($request, $this->auth->attempt($request->validated(), $request->ip()));
    }

    public function sendPasswordResetOtp(SendPasswordResetOtpRequest $request): JsonResponse
    {
        $this->passwordReset->sendOtp($request->validated('email'));

        return response()->json([
            'message' => 'If this email address is registered to an active account, a verification code has been dispatched.',
        ]);
    }

    public function verifyPasswordResetOtp(VerifyPasswordResetOtpRequest $request): JsonResponse
    {
        $this->passwordReset->verifyOtp(
            $request->validated('email'),
            $request->validated('otp')
        );

        return response()->json([
            'ok' => true,
            'message' => 'Verification code confirmed. Please choose your new password.',
        ]);
    }

    public function resetPasswordWithOtp(ResetPasswordWithOtpRequest $request): JsonResponse
    {
        $this->passwordReset->resetPassword(
            $request->validated('email'),
            $request->validated('otp'),
            $request->validated('password')
        );

        return response()->json([
            'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
        ]);
    }

    private function loginResponse(Request $request, LoginResult $result): JsonResponse
    {
        if (! $result->succeeded()) {
            return response()->json(['message' => $result->message], $result->status);
        }

        Auth::login($result->user, false);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($result->user), 'redirect' => $result->user->homeUrl()]);
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
