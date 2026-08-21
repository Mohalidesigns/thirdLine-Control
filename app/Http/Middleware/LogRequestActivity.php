<?php

namespace App\Http\Middleware;

use App\Services\ActivityContext;
use App\Services\AuditTrailService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activity Log (CR3) fallback layer: records any state-changing request
 * (POST/PUT/PATCH/DELETE) by an authenticated user that the domain, CRUD
 * or auth layers did not already record this request (ActivityContext
 * dedup flag — never two rows for one action). GETs are skipped: the log
 * tracks what a user DID, not what they saw — and it never logs the
 * reading of itself.
 *
 * Runs in terminate(), after the response is gone, so it adds nothing to
 * the user's latency and can never abort their transaction.
 */
class LogRequestActivity
{
    /** Route-name prefixes never logged here (auth flow has its own listener). */
    private const IGNORED_ROUTE_PREFIXES = [
        'login', 'logout', 'password.', 'sanctum.',
        'settings.activity-log', 'admin.audit-log',
    ];

    /** URL path prefixes skipped even when the route is unnamed. */
    private const IGNORED_PATH_PREFIXES = [
        'up', '_debugbar', 'telescope', 'horizon', 'build', 'livewire',
    ];

    public function __construct(private AuditTrailService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        app(ActivityContext::class)->reset();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldLog($request, $response)) {
                return;
            }

            $routeName = $this->routeName($request);
            $status = $response->getStatusCode();
            $readable = strtoupper($request->method()).' /'.ltrim($request->path(), '/');

            // A denied request (403) is recorded by DenialAuditor with the
            // richer entity context; a 419 is a CSRF/session boundary event
            // worth its own record.
            $event = $status === 419 ? 'session_expired' : ($routeName ?? 'request.'.strtolower($request->method()));

            $this->audit->recordEvent(
                $event,
                $this->subjectType($request) ?? 'request',
                $readable,
                ['params' => $this->routeParameterIds($request)],
                array_filter([
                    'entity_id' => $this->subjectId($request),
                    'subject_label' => $this->subjectLabel($request),
                    'status_code' => $status,
                    // Route-name events get a readable label, never the raw key.
                    'event_label' => $status === 419 ? null : $readable,
                ], fn ($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        // Something in the request already wrote an audit row — the
        // fallback standing down is what keeps one action to one record.
        if (app(ActivityContext::class)->wasLogged()) {
            return false;
        }

        // 2xx/3xx are real state changes; 419 is a session boundary worth
        // keeping. 403 is DenialAuditor's job; other 4xx/5xx are noise.
        $status = $response->getStatusCode();
        if ($status >= 400 && $status !== 419) {
            return false;
        }

        $routeName = $this->routeName($request) ?? '';
        foreach (self::IGNORED_ROUTE_PREFIXES as $prefix) {
            if ($routeName !== '' && str_starts_with($routeName, $prefix)) {
                return false;
            }
        }

        $path = ltrim($request->path(), '/');
        foreach (self::IGNORED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** Named routes only — an auto-generated name is not an identity. */
    private function routeName(Request $request): ?string
    {
        $name = $request->route()?->getName();

        return ($name && ! str_starts_with($name, 'generated::')) ? $name : null;
    }

    private function firstModelParameter(Request $request): ?Model
    {
        try {
            return collect($request->route()?->parameters() ?? [])
                ->first(fn ($p) => $p instanceof Model);
        } catch (\Throwable) {
            return null;
        }
    }

    private function subjectType(Request $request): ?string
    {
        return $this->firstModelParameter($request)?->getMorphClass();
    }

    private function subjectId(Request $request): ?int
    {
        $key = $this->firstModelParameter($request)?->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    private function subjectLabel(Request $request): ?string
    {
        $model = $this->firstModelParameter($request);

        if (! $model) {
            return null;
        }

        foreach (['name', 'title', 'reference', 'control_ref', 'code'] as $attr) {
            $value = $model->getAttribute($attr);
            if (is_string($value) && $value !== '') {
                return substr($value, 0, 255);
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    /**
     * Route parameter IDs only — never the request body. The payload is
     * noisy, potentially large, and can carry personal data or secrets
     * that must not be duplicated into the log.
     *
     * @return array<string, int|string>
     */
    private function routeParameterIds(Request $request): array
    {
        try {
            $params = $request->route()?->parameters() ?? [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($params as $key => $value) {
            if ($value instanceof Model) {
                $out[$key] = $value->getKey();
            } elseif (is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
