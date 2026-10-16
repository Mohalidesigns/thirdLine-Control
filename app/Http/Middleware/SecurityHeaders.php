<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every web response (16.3). The CSP is
 * deliberately conservative: the SPA serves its own assets and inlines
 * Vite's style tags; nothing loads from a third-party origin — which is
 * also the residency posture (R5).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // DEF-021: PHP advertises its exact version on every response via
        // X-Powered-By unless expose_php is off — strip it here so the
        // posture does not depend on php.ini being set correctly per host.
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $response->headers->has('Content-Security-Policy')) {
            // While `npm run dev` is up, scripts, styles and assets come from
            // the Vite dev server's own origin, which 'self' does not cover.
            $vite = $this->viteDevOrigin();

            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // Vite dev server needs ws + eval in local development only.
                app()->environment('local')
                    ? "script-src 'self' 'unsafe-inline' 'unsafe-eval'{$vite}"
                    : "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'{$vite}",
                "img-src 'self' data: blob:{$vite}",
                "font-src 'self' data:{$vite}",
                app()->environment('local') ? "connect-src 'self' ws: wss:{$vite}" : "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }

    /**
     * The Vite dev server origin, prefixed with a space for appending to a
     * CSP directive — or '' outside local development or when Vite is not
     * running hot, so built assets keep the strict same-origin policy.
     */
    private function viteDevOrigin(): string
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return '';
        }

        $origin = trim((string) @file_get_contents(Vite::hotFile()));

        return $origin === '' ? '' : ' '.$origin;
    }
}
