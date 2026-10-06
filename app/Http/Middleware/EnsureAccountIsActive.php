<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! User::whereKey($user->id)->where('is_active', true)->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Your account has been deactivated.'], 401);
            }

            return redirect()->route('cashier.login');
        }

        return $next($request);
    }
}
