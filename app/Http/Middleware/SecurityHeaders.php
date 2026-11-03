<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
            $vite = $this->viteDevOrigin();

            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // Vite dev server needs ws + eval in local development only.
                app()->environment('local')
                    ? trim("script-src 'self' 'unsafe-inline' 'unsafe-eval' {$vite}")
                    : "script-src 'self' 'unsafe-inline'",
                trim("style-src 'self' 'unsafe-inline' {$vite}"),
                trim("img-src 'self' data: blob: {$vite}"),
                trim("font-src 'self' data: {$vite}"),
                app()->environment('local') ? "connect-src 'self' ws: wss:" : "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }

    /**
     * The running Vite dev server's origin (from public/hot), so `npm run dev`
     * can serve modules from its own port. Local only; empty otherwise.
     */
    private function viteDevOrigin(): string
    {
        $hot = public_path('hot');

        if (! app()->environment('local') || ! is_file($hot)) {
            return '';
        }

        $parts = parse_url(trim((string) file_get_contents($hot)));

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
