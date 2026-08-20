<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Submission idempotency for the human-facing create forms (DEF-018,
 * §12.D.19/20). A double-clicked submit, a browser back-and-resubmit, or a
 * client retry after a timeout re-delivers an IDENTICAL payload; the
 * duplicate is absorbed with a flash notice instead of minting a second
 * record with its own reference, escalation ladder and closure obligation.
 *
 * The machine surface already has this via the Idempotency-Key header on
 * /api/v1 — this closes the same gap for the web forms.
 */
class DedupeFormSubmission
{
    /**
     * POST create endpoints for the registers where a duplicate is a
     * costly record rather than an inert row. Exact request paths.
     */
    private const GUARDED_PATHS = [
        'exceptions',
        'controls',
        'risks',
        'incidents',
        'complaints',
        'improvements',
        'treatments',
        'documents',
        'vendors',
        'entities',
        'cases',
    ];

    private const REMEMBER_MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')
            || ! $request->user()
            || ! in_array($request->path(), self::GUARDED_PATHS, true)) {
            return $next($request);
        }

        $key = $this->fingerprint($request);

        if (Cache::has($key)) {
            return redirect()->back()->with('info', 'This form was already submitted — the duplicate was ignored.');
        }

        $response = $next($request);

        // Remember only submissions that succeeded; a validation failure
        // must stay repeatable so the corrected form can be re-posted.
        if (($response->isRedirection() || $response->isSuccessful())
            && ! $request->session()->has('errors')) {
            Cache::put($key, true, now()->addMinutes(self::REMEMBER_MINUTES));
        }

        return $response;
    }

    private function fingerprint(Request $request): string
    {
        $payload = collect($request->input())->except(['_token'])->sortKeys()->toArray();

        $files = collect($request->allFiles())
            ->map(fn ($file) => is_array($file)
                ? collect($file)->map(fn ($f) => $f->getClientOriginalName().':'.$f->getSize())->implode(',')
                : $file->getClientOriginalName().':'.$file->getSize())
            ->sortKeys()
            ->toArray();

        return 'form-dedupe:'.hash('sha256', implode('|', [
            $request->user()->id,
            $request->path(),
            json_encode($payload),
            json_encode($files),
        ]));
    }
}
