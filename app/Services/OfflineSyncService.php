<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\CsaCampaign;
use App\Models\CsaResponse;
use App\Models\OfflineAction;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The server side of the offline outbox (15.1).
 *
 * Actions queued on a device replay here IN ORDER, and each one is
 * validated exactly as if it had been submitted online: same policies,
 * same service methods, same audit trail. Three rules are structural:
 *
 *  - WHITELIST, NOT BLACKLIST. Only the four offline-safe action types
 *    exist; an approval, review or SoD-gated transition is not "blocked",
 *    it simply has no handler and is rejected with a reason the UI can
 *    show (R2 — a maker-checker gate must not be passable from a queue).
 *
 *  - IDEMPOTENT PER CLIENT ID. The device generated a UUID when the user
 *    acted; a retry after a dropped response returns the recorded outcome
 *    instead of applying the action twice.
 *
 *  - CONFLICTS SURFACE, NEVER OVERWRITE. When the payload carries the
 *    record state the device last saw (expected_updated_at) and the
 *    server has moved on, the action parks as `conflict` and the response
 *    carries the server's current state for the "server changed this"
 *    prompt. Nothing is silently merged.
 */
class OfflineSyncService
{
    public const OFFLINE_BLOCKED_REASON = 'This action needs a live connection: approvals, reviews and '
        .'status transitions with segregation-of-duties checks cannot be queued offline.';

    public function __construct(
        private AttestationService $attestations,
        private CsaService $csa,
        private TestingService $testing,
    ) {}

    /**
     * @param  array<int, array{client_id: string, type: string, payload: array, acted_at?: ?string}>  $actions
     * @return array<int, array> one outcome per action, same order
     */
    public function process(User $user, array $actions): array
    {
        $outcomes = [];

        foreach ($actions as $action) {
            $outcomes[] = $this->processOne($user, $action);
        }

        return $outcomes;
    }

    private function processOne(User $user, array $action): array
    {
        $clientId = (string) $action['client_id'];

        // Replay of an action this device (or another tab) already synced:
        // return the recorded outcome, apply nothing.
        $existing = OfflineAction::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->first();

        if ($existing) {
            return $this->outcome($existing, replayed: true);
        }

        $record = fn (string $status, ?array $result = null, ?string $reason = null) => OfflineAction::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'client_id' => $clientId,
            'action_type' => (string) $action['type'],
            'payload' => $action['payload'] ?? [],
            'status' => $status,
            'result' => $result,
            'reason' => $reason,
            'acted_at' => $action['acted_at'] ?? null,
        ]);

        $handler = match ($action['type'] ?? null) {
            'attestation.complete' => $this->completeAttestation(...),
            'csa.answers' => $this->saveCsaAnswers(...),
            'test.record-result' => $this->recordCheckResult(...),
            'exception.comment' => $this->addExceptionComment(...),
            default => null,
        };

        if ($handler === null) {
            return $this->outcome($record('rejected', reason: self::OFFLINE_BLOCKED_REASON));
        }

        try {
            [$status, $result, $reason] = $handler($user, $action['payload'] ?? []);
        } catch (ValidationException $e) {
            [$status, $result, $reason] = ['rejected', null, collect($e->errors())->flatten()->implode(' ')];
        } catch (AuthorizationException) {
            [$status, $result, $reason] = ['rejected', null, 'You are not permitted to perform this action.'];
        } catch (ModelNotFoundException) {
            [$status, $result, $reason] = ['rejected', null, 'The record this action targets no longer exists or is not visible to you.'];
        }

        return $this->outcome($record($status, $result, $reason));
    }

    // ── Handlers — each returns [status, result, reason] ─────────────────

    private function completeAttestation(User $user, array $payload): array
    {
        $data = $this->validate($payload, [
            'campaign_id' => ['required', 'integer'],
            'accepted' => ['accepted'],
        ]);

        if (! $user->can('respond campaigns')) {
            return ['rejected', null, 'You are not permitted to perform this action.'];
        }

        $campaign = CsaCampaign::query()->findOrFail($data['campaign_id']);
        $subject = $this->attestations->resolveSubject($campaign);

        if (! $subject) {
            return ['rejected', null, 'This campaign has no attestable subject configured.'];
        }

        $attestation = $this->attestations->attest($campaign, $user, $subject, 'offline');

        return ['applied', ['attestation_id' => $attestation->id], null];
    }

    private function saveCsaAnswers(User $user, array $payload): array
    {
        $data = $this->validate($payload, [
            'response_id' => ['required', 'integer'],
            'answers' => ['required', 'array'],
            'submit' => ['boolean'],
            'expected_updated_at' => ['nullable', 'date'],
        ]);

        $response = CsaResponse::query()->findOrFail($data['response_id']);

        Gate::forUser($user)->authorize('respond', $response);

        if ($conflict = $this->conflict($response->updated_at, $data['expected_updated_at'] ?? null)) {
            return ['conflict', [
                'server_state' => [
                    'status' => $response->status,
                    'answers' => $response->answers,
                    'updated_at' => $response->updated_at?->toIso8601String(),
                ],
            ], $conflict];
        }

        $response = $this->csa->saveAnswers($response, $data['answers'], $user, (bool) ($data['submit'] ?? false));

        return ['applied', ['response_id' => $response->id, 'status' => $response->status], null];
    }

    private function recordCheckResult(User $user, array $payload): array
    {
        $data = $this->validate($payload, [
            'test_instance_id' => ['required', 'integer'],
            'check_item_id' => ['required', 'integer'],
            'result' => ['required', 'in:Pass,Fail,NA'],
            'comment' => ['nullable', 'string'],
            'expected_updated_at' => ['nullable', 'date'],
        ]);

        $instance = TestInstance::query()->findOrFail($data['test_instance_id']);

        Gate::forUser($user)->authorize('execute', $instance);

        if ($conflict = $this->conflict($instance->updated_at, $data['expected_updated_at'] ?? null)) {
            return ['conflict', [
                'server_state' => [
                    'status' => $instance->status,
                    'updated_at' => $instance->updated_at?->toIso8601String(),
                ],
            ], $conflict];
        }

        $result = $this->testing->recordResult(
            $instance, (int) $data['check_item_id'], $data['result'], $data['comment'] ?? null, $user,
        );

        return ['applied', ['check_result_id' => $result->id], null];
    }

    private function addExceptionComment(User $user, array $payload): array
    {
        $data = $this->validate($payload, [
            'exception_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $exception = ControlException::query()->findOrFail($data['exception_id']);

        Gate::forUser($user)->authorize('comment', $exception);

        $activity = $exception->logActivity('Comment', null, null, $data['note']);

        return ['applied', ['activity_id' => $activity->id], null];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Server moved past what the device saw → "server changed this". */
    private function conflict(?Carbon $serverUpdatedAt, ?string $expected): ?string
    {
        if ($expected === null || $serverUpdatedAt === null) {
            return null;
        }

        return $serverUpdatedAt->greaterThan(Carbon::parse($expected))
            ? 'The server copy changed after this device last saw it ('
                .$serverUpdatedAt->toIso8601String().'). Review the server version before retrying.'
            : null;
    }

    private function validate(array $payload, array $rules): array
    {
        return Validator::make($payload, $rules)->validate();
    }

    private function outcome(OfflineAction $action, bool $replayed = false): array
    {
        return [
            'client_id' => $action->client_id,
            'status' => $action->status,
            'reason' => $action->reason,
            'result' => $action->result,
            'replayed' => $replayed,
        ];
    }
}
