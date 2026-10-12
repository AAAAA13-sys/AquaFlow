<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRoleAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->isEmployee()) {
            $user->load('employee');
            abort_unless($user->employee && $user->employee->is_active, 403, 'Employee access is inactive.');
            abort_unless($request->is('employee', 'api/employee/*', 'api/profile*', 'api/auth/logout', 'api/auth/session', '/'), 403);
        }
        if ($user && $request->is('employee', 'api/employee/*')) {
            if (! $request->is('api/employee/attendance*', 'api/employee/profile*')) {
                abort_unless($user->isEmployee(), 403);
            }
        }

        return $next($request);
    }
}
