<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict owner routes, or operational routes shared with managers.
 *
 * This is the server-side half of RBAC; the front-end only hides buttons.
 */
class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next, string $access = 'owner'): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication required.');
        }

        if (! ($access === 'management' ? $user->isAdmin() : $user->isSuperAdmin())) {
            if ($request->is('api/*') || $request->expectsJson()) {
                abort(403, 'Owner access required.');
            }

            // Denied page navigation returns to the caller's own workspace.
            return redirect($user->homeUrl());
        }

        return $next($request);
    }
}
