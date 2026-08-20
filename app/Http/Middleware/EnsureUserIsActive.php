<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivation is the primary incident-response control: it must end a
 * session already in flight, not just refuse the next login (DEF-007).
 * LoginRequest checks is_active only at POST /login; this re-checks it on
 * every authenticated request and terminates the session if it has been
 * revoked mid-flight.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Read the flag from the database, not the hydrated instance — the
        // guard caches the user, and a deactivation that happened after
        // hydration must still take effect on this request.
        if ($user && ! $user->newQuery()->withoutGlobalScopes()->whereKey($user->getKey())->value('is_active')) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'This account has been deactivated.');
            }

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated.']);
        }

        return $next($request);
    }
}
