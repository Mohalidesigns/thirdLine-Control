<?php

namespace App\Http\Middleware;

use App\Models\IntegrationConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed per-tenant API key authentication for /api/v1 (FR-11.6).
 * The caller presents X-Api-Key; keys are stored hashed, scoped to one
 * tenant's active integration config. A malformed key can never cross
 * tenants — the resolved config drives all downstream scoping.
 */
class AuthenticateIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key');

        if (! $key) {
            return response()->json(['message' => 'Missing X-Api-Key header.'], 401);
        }

        $config = IntegrationConfig::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNotNull('inbound_key_hash')
            ->get()
            ->first(fn (IntegrationConfig $candidate) => hash_equals($candidate->inbound_key_hash, hash('sha256', $key)));

        if (! $config) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $request->attributes->set('integration_config', $config);
        $request->attributes->set('integration_tenant_id', $config->tenant_id);

        return $next($request);
    }
}
