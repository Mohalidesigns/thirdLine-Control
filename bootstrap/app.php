<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateIntegration;
use App\Http\Middleware\DedupeFormSubmission;
use App\Http\Middleware\EnforceMfa;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogRequestActivity;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use App\Services\DenialAuditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id first, so every later middleware and handler logs
        // with it; security headers on the way out (16.3).
        $middleware->web(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            DedupeFormSubmission::class,
            HandleInertiaRequests::class,
            EnforceMfa::class,
            RequirePasswordChange::class,
            SecurityHeaders::class,
            // Terminate-time activity-log fallback for state-changing
            // requests the domain/CRUD/auth layers didn't record (CR3).
            LogRequestActivity::class,
        ]);

        // SAML IdPs POST assertions cross-origin — the assertion itself is
        // the proof, validated in SamlService (signature + replay). The
        // Phase 15 webhooks are machine callers with their own proof:
        // Meta's HMAC signature, the aggregator/USSD shared tokens.
        $middleware->validateCsrfTokens(except: [
            'auth/sso/*/acs',
            'webhooks/*',
        ]);

        $middleware->alias([
            'integration.auth' => AuthenticateIntegration::class,
            'feature' => EnsureFeatureEnabled::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // DEF-005: a refused authorisation must leave a record. Returning
        // null falls through to the default 403 rendering.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            app(DenialAuditor::class)->record($request);

            return null;
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 403) {
                app(DenialAuditor::class)->record($request);
            }

            return null;
        });
    })->create();
