<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user created with a temporary password is hard-blocked into choosing
 * their own before touching anything else. Only the change screen and
 * logout stay reachable — the same shape as the MFA enrolment gate.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user
            || ! $user->must_change_password
            || $request->routeIs('password.force', 'password.force.update', 'logout', 'login')) {
            return $next($request);
        }

        return $request->expectsJson()
            ? abort(403, 'Password change required.')
            : redirect()->route('password.force');
    }
}
