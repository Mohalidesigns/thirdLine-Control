<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for observability (16.3): every request carries one,
 * every log line written during it includes it, and the response echoes
 * it so a support ticket can quote a single id that joins the browser,
 * the app log and the queue.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        Log::withContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->id,
            'tenant_id' => $request->user()?->tenant_id,
        ]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
