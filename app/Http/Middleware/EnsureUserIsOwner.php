<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to the station owner (admin role).
 *
 * This is the server-side half of RBAC; the front-end only hides buttons.
 */
class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication required.');
        }

        if (! $user->isAdmin()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                abort(403, 'Owner access required.');
            }

            // A cashier who opens an owner URL lands back in the terminal.
            return redirect()->route('cashier.index');
        }

        return $next($request);
    }
}
