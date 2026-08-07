<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for feature-flagged modules: `->middleware('feature:sso')`.
 */
class EnsureFeatureEnabled
{
    public function __construct(private FeatureService $features) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless($this->features->enabled($key, $request->user()), 404);

        return $next($request);
    }
}
