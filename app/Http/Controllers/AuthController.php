<?php

namespace App\Http\Controllers;

use App\Data\LoginResult;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\OwnerLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\OwnerEmailService;
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
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->loginResponse($request, $this->auth->attemptCashier($request->validated(), $request->ip()));
    }

    public function loginOwner(OwnerLoginRequest $request): JsonResponse
    {
        $result = $this->auth->attemptOwner($request->validated(), $request->ip());
        if (! $result->succeeded()) {
            return $this->loginResponse($request, $result);
        }
        Auth::logout();
        // Preserve the pending code if a resend is rejected by the cooldown.
        if (! $request->session()->has('owner_challenge')) {
            $request->session()->regenerate();
        }
        $id = app(OwnerEmailService::class)->issue($result->user, $request->session()->getId());
        $request->session()->put('owner_challenge', $id);

        return response()->json(['pending' => true, 'message' => 'Sign-in code queued for your registered email. Check your inbox and spam folder.']);
    }

    public function verifyEmail(Request $request, OwnerEmailService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);
        $challengeId = (string) $request->session()->get('owner_challenge');
        $user = $challengeId ? $service->verify($challengeId, $request->session()->getId(), $data['code']) : null;

        if (! $user || $user->is_bootstrap) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }
        $codes = $service->enroll($user);
        $request->session()->forget('owner_challenge');
        Auth::login($user, false);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user), 'recovery_codes' => $codes]);
    }

    public function recover(Request $request, OwnerEmailService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'size:20']]);
        $result = $this->auth->attemptOwner($data, $request->ip());
        $user = $result->succeeded() && $result->user->isAdmin() ? $service->recovery($result->user, $data['code']) : null;

        return $this->loginResponse($request, $user ? LoginResult::ok($user) : LoginResult::failed('Invalid credentials or recovery code.'));
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
