<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRoleAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->must_change_password && $request->is('api/*')
            && ! $request->is('api/profile', 'api/auth/session', 'api/auth/logout')) {
            return response()->json(['message' => 'Change your password before starting work.', 'code' => 'password_change_required'], 403);
        }
        if ($user) {
            abort_unless(in_array($user->role, \App\Models\User::ROLES, true) && ! $user->is_bootstrap, 403);
        }
        if ($user && $user->isEmployee()) {
            $user->load('employee');
            abort_unless($user->employee && $user->employee->is_active, 403, 'Employee access is inactive.');
            // `api/defects` is matched without a trailing wildcard so staff can
            // file a report while `api/defects/{id}/approve|reject` — which write
            // stock off — stays out of reach.
            abort_unless($request->is('employee', 'employee/*', 'api/employee/*', 'api/activity', 'api/defects', 'api/profile*', 'api/auth/logout', 'api/auth/session', '/', 'owner/login'), 403);
        }
        if ($user && $request->is('employee', 'employee/*', 'api/employee/*')) {
            if (! $request->is('api/employee/profile*')) {
                abort_unless($user->isEmployee() || $user->isAdmin(), 403);
            }
        }

        return $next($request);
    }
}
