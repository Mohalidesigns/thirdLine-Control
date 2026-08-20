<?php

namespace Tests\e2e;

use App\Models\ChunkedUpload;
use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\TestInstance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;

/**
 * The other half of TC-02-05.
 *
 * `AuthorisationMatrixTest` sweeps routes that *declare* a role or
 * permission requirement. This file covers the 121 mutating routes that
 * declare none, and therefore depend entirely on an in-controller check.
 * A route in that set with no check at all is an unguarded mutation.
 *
 * Static triage identifies candidates; the dynamic tests below decide
 * whether a candidate is actually a hole. The static result is also kept
 * as a regression guard, so a *new* un-gated route without a check fails
 * the build rather than going unnoticed.
 */
class UngatedRouteAuthorisationTest extends E2ETestCase
{
    /**
     * Un-gated mutating routes with no in-controller authorisation check,
     * each reviewed and accounted for. Anything not on this list is new
     * and must be reviewed before it is added.
     *
     * uri => why no role/permission check is correct here
     */
    private const REVIEWED = [
        // Machine callers with their own authentication.
        'api/v1/controls' => 'integration.auth middleware — per-tenant X-Api-Key',
        'api/v1/assurance/activities' => 'integration.auth middleware',
        'api/v1/assurance/findings' => 'integration.auth middleware',
        'webhooks/whatsapp' => 'HMAC signature (untested — TC-New-01)',
        'webhooks/sms/receipts' => 'shared token (untested — TC-New-01)',
        'webhooks/ussd' => 'shared token (untested — TC-New-01); feature disabled',

        // Unauthenticated by design.
        'login' => 'authentication endpoint',
        'logout' => 'ends own session',
        'speak-up/status' => 'anonymous whistleblowing — access is by case token',
        'speak-up/reply' => 'anonymous whistleblowing — access is by case token',

        // Act only on the caller's own records.
        'mfa/verify' => 'own MFA enrolment',
        'mfa/email-otp' => 'own MFA enrolment',
        'mfa/confirm' => 'own MFA enrolment',
        'mfa' => 'own MFA enrolment',
        'notifications/read-all' => 'own notifications',
        'push/subscriptions' => 'own device subscription',
        'offline/outbox' => 'own offline queue',
        'settings/low-bandwidth' => 'own display preference',
        'saved-views' => 'own saved view',

        // Ownership enforced in a private helper the static scan cannot see.
        'uploads/chunked' => 'ChunkedUploadController::ownUpload() scopes by user_id',
        'uploads/chunked/{uuid}/chunks' => 'ChunkedUploadController::ownUpload() scopes by user_id',
        'uploads/chunked/{uuid}/complete' => 'ChunkedUploadController::ownUpload() scopes by user_id',
        'uploads/chunked/{uuid}' => 'ChunkedUploadController::ownUpload() scopes by user_id',

        // Open finding — see DEF-009.
        'evidence' => 'DEF-009: any authenticated user may attach evidence to any record in their tenant',
    ];

    /** @return array<int, string> uris of un-gated mutating routes with no visible check */
    private function ungatedWithoutCheck(): array
    {
        $found = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^(role|permission|role_or_permission):/', $middleware)) {
                    continue 2;
                }
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);

            try {
                $reflection = new ReflectionMethod($class, $method);
            } catch (\Throwable) {
                continue;
            }

            $source = implode('', array_slice(
                file($reflection->getFileName()),
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1
            ));

            if ($this->containsAuthorisationCheck($source)) {
                continue;
            }

            // A FormRequest may carry the check in its own authorize(),
            // which is just as valid as an inline one — LinkageController
            // and the notification preferences screen both do this.
            if ($this->formRequestAuthorises($reflection)) {
                continue;
            }

            $found[$route->uri()] = true;
        }

        return array_keys($found);
    }

    private function containsAuthorisationCheck(string $source): bool
    {
        return (bool) preg_match(
            '/\$this->authorize\(|Gate::|->cannot\(|->can\(|abort_unless\(|abort_if\(|authorizeResource/',
            $source
        );
    }

    /**
     * True when a FormRequest parameter carries a real authorize() body —
     * anything other than the scaffolded `return true;`.
     */
    private function formRequestAuthorises(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (! is_subclass_of($name, FormRequest::class)) {
                continue;
            }

            try {
                $authorize = new ReflectionMethod($name, 'authorize');
            } catch (\Throwable) {
                continue;
            }

            $body = implode('', array_slice(
                file($authorize->getFileName()),
                $authorize->getStartLine() - 1,
                $authorize->getEndLine() - $authorize->getStartLine() + 1
            ));

            // `return true;` is the scaffold default and authorises nothing.
            if (! preg_match('/return\s+true\s*;/', $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Regression guard. Every un-gated mutating route without an inline
     * check must be one that has been reviewed and recorded above.
     */
    public function test_no_unreviewed_route_mutates_without_an_authorisation_check(): void
    {
        $unreviewed = array_values(array_diff($this->ungatedWithoutCheck(), array_keys(self::REVIEWED)));

        $this->assertSame(
            [],
            $unreviewed,
            'A mutating route has neither role/permission middleware nor an in-controller authorisation '
            ."check, and has not been reviewed:\n  ".implode("\n  ", $unreviewed)
            ."\n\nEither add a check, or add it to self::REVIEWED with the reason it is safe."
        );
    }

    /** The reviewed list must not rot: everything on it must still exist. */
    public function test_reviewed_list_contains_no_stale_entries(): void
    {
        $stale = array_values(array_diff(array_keys(self::REVIEWED), $this->ungatedWithoutCheck()));

        $this->assertSame(
            [],
            $stale,
            'These routes are on the reviewed list but no longer match it — they have gained a check, '
            ."been gated, or been removed. Delete them from self::REVIEWED:\n  ".implode("\n  ", $stale)
        );
    }

    // -----------------------------------------------------------------
    // Dynamic confirmation — the candidates that mattered
    // -----------------------------------------------------------------

    /**
     * DEF-009. Evidence upload declares no role or permission requirement
     * and performs no authorisation check. Its counterpart, download(),
     * correctly restricts to System Administrator / Control Function Head
     * / Control Officer or the uploader — so read is gated and write is
     * not.
     */
    public function test_read_only_roles_cannot_attach_evidence_to_a_control_test(): void
    {
        $instance = TestInstance::withoutGlobalScopes()->firstOrFail();

        $admitted = [];

        // Executive Viewer is the board tier and Line Manager reads across
        // — the BRD gives neither any authoring capability at all.
        foreach (['exec', 'manager'] as $account) {
            $before = Evidence::withoutGlobalScopes()->count();

            $this->actingAs($this->account($account))->post('/evidence', [
                'file' => UploadedFile::fake()->create('planted-by-'.$account.'.csv', 8, 'text/csv'),
                'linked_type' => 'test_instance',
                'linked_id' => $instance->id,
                'contains_personal_data' => false,
                'classification' => 'Internal',
            ]);

            if (Evidence::withoutGlobalScopes()->count() > $before) {
                $admitted[] = $account;
            }
        }

        $this->assertSame(
            [],
            $admitted,
            'DEF-009: read-only roles attached evidence to a control test — '.implode(', ', $admitted).'. '
            .'Evidence is the artefact the control function relies on when verifying remediation, so who '
            .'may place it matters as much as who may read it.'
        );
    }

    /** Cross-tenant evidence attachment must fail regardless of DEF-009. */
    public function test_evidence_cannot_be_attached_to_another_tenants_record(): void
    {
        $foreign = $this->otherTenantException();
        $before = Evidence::withoutGlobalScopes()->where('linked_id', $foreign->id)->count();

        $this->actingAs($this->account('officer'))->post('/evidence', [
            'file' => UploadedFile::fake()->create('cross-tenant.csv', 8, 'text/csv'),
            'linked_type' => 'exception',
            'linked_id' => $foreign->id,
            'contains_personal_data' => false,
            'classification' => 'Internal',
        ]);

        $this->assertSame(
            $before,
            Evidence::withoutGlobalScopes()->where('linked_id', $foreign->id)->count(),
            'CRITICAL: evidence was attached to another tenant\'s exception.'
        );
    }

    /**
     * Confirms the static scan's chunked-upload candidates are false
     * positives: ownUpload() scopes by user_id, so one user cannot append
     * to, complete or abort another's upload.
     */
    public function test_chunked_upload_cannot_be_hijacked_by_another_user(): void
    {
        $owner = $this->account('officer');
        $attacker = $this->account('owner');

        $created = $this->actingAs($owner)->postJson('/uploads/chunked', [
            'file_name' => 'e2e-chunked.csv',
            'mime_type' => 'text/csv',
            'total_size' => 1024,
            'total_chunks' => 2,
            'meta' => [
                'linked_type' => 'exception',
                'linked_id' => ControlException::withoutGlobalScopes()->firstOrFail()->id,
                'contains_personal_data' => false,
            ],
        ]);

        $created->assertSuccessful();
        $uuid = $created->json('uuid');
        $this->assertNotNull($uuid);

        foreach (['appendChunk' => "/uploads/chunked/{$uuid}/chunks", 'complete' => "/uploads/chunked/{$uuid}/complete"] as $call) {
            $this->actingAs($attacker)->postJson($call, [
                'index' => 0,
                'chunk' => UploadedFile::fake()->create('chunk', 1),
            ])->assertNotFound();
        }

        $this->actingAs($attacker)->deleteJson("/uploads/chunked/{$uuid}")->assertNotFound();

        $this->assertNotNull(
            ChunkedUpload::where('uuid', $uuid)->first(),
            'Another user aborted an upload they do not own.'
        );
    }
}
