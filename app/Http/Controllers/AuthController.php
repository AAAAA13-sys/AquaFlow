<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\OwnerLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Session authentication.
 *
 * Passwords and the Owner PIN are verified against bcrypt hashes; the client
 * never receives them. Login routes are rate limited (see routes/web.php).
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()
            ->where('username', $credentials['username'])
            ->where('is_active', true)
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid username or password.'], 401);
        }

        // Check the role *before* authenticating: signing an owner in and back
        // out would migrate the session and rotate the CSRF token.
        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Owner accounts use the separate Owner Login page.',
            ], 403);
        }

        Auth::login($user, false);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user)]);
    }

    public function loginOwner(OwnerLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()
            ->where('username', $credentials['username'])
            ->where('is_active', true)
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid owner username or password.'], 401);
        }

        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Invalid owner account.'], 401);
        }

        if (empty($user->pin) || ! Hash::check($credentials['pin'], $user->pin)) {
            return response()->json(['message' => 'Wrong Owner PIN.'], 401);
        }

        Auth::login($user, false);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user)]);
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
