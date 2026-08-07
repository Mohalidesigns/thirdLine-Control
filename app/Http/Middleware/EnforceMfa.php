<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use App\Services\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 7.2 gate, applied to the whole authenticated web surface:
 *  - a user with MFA enabled must pass the challenge each session;
 *  - a user in an MFA-enforced role past the grace period is hard-blocked
 *    into enrolment. Only the mfa.* routes and logout stay reachable.
 */
class EnforceMfa
{
    public function __construct(
        private MfaService $mfa,
        private FeatureService $features,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user
            || ! $this->features->enabled('mfa', $user)
            || $request->routeIs('mfa.*', 'logout', 'login')) {
            return $next($request);
        }

        if ($user->hasMfaEnabled() && ! $request->session()->get('mfa.verified')) {
            return $request->expectsJson()
                ? abort(403, 'MFA challenge required.')
                : redirect()->route('mfa.challenge');
        }

        if (! $user->hasMfaEnabled() && $this->mfa->isPastGracePeriod($user)) {
            return $request->expectsJson()
                ? abort(403, 'MFA enrolment required.')
                : redirect()->route('mfa.setup')
                    ->with('error', 'Multi-factor authentication is required for your role. Enrol to continue.');
        }

        return $next($request);
    }
}
