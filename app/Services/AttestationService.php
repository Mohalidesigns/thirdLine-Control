<?php

namespace App\Services;

use App\Http\Controllers\AttestationController;
use App\Models\Attestation;
use App\Models\CsaCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attestation campaigns (9.5): a user signs the exact text of a policy,
 * code of conduct, control or framework. The signed text is snapshotted —
 * a later edit to the source can never change what was attested.
 */
class AttestationService
{
    /**
     * Record an attestation inside a campaign. Idempotent per
     * (user, campaign, attestable): re-attesting returns the original.
     */
    public function attest(CsaCampaign $campaign, User $user, Model $attestable, ?string $method = 'web'): Attestation
    {
        if ($campaign->campaign_type !== 'attestation') {
            throw ValidationException::withMessages([
                'campaign' => 'Attestations can only be recorded on an attestation campaign.',
            ]);
        }

        if (! $campaign->isOpen()) {
            throw ValidationException::withMessages([
                'campaign' => 'This attestation campaign is not open.',
            ]);
        }

        return DB::transaction(function () use ($campaign, $user, $attestable, $method) {
            $existing = Attestation::query()
                ->where('user_id', $user->id)
                ->where('campaign_id', $campaign->id)
                ->where('attestable_type', $attestable->getMorphClass())
                ->where('attestable_id', $attestable->getKey())
                ->first();

            if ($existing) {
                return $existing;
            }

            $attestation = Attestation::create([
                'tenant_id' => $campaign->tenant_id,
                'attestable_type' => $attestable->getMorphClass(),
                'attestable_id' => $attestable->getKey(),
                'user_id' => $user->id,
                'campaign_id' => $campaign->id,
                'attested_at' => now(),
                'attestation_text_snapshot' => $this->snapshotText($attestable),
                'ip_address' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 500),
                'method' => in_array($method, Attestation::METHODS, true) ? $method : 'web',
                'version_attested' => $this->versionOf($attestable),
            ]);

            // Mark the user's campaign response complete, if one exists.
            $campaign->responses()
                ->where('respondent_id', $user->id)
                ->whereIn('status', ['Not Started', 'In Progress'])
                ->update(['status' => 'Submitted', 'submitted_at' => now()]);

            $attestation->auditAction('attested', null, [
                'attestable' => $attestable->getMorphClass().'#'.$attestable->getKey(),
                'version' => $attestation->version_attested,
            ]);

            return $attestation;
        });
    }

    /**
     * Users in scope who have not attested — the escalation feed. Uses the
     * campaign's generated responses as the invite list.
     */
    public function outstanding(CsaCampaign $campaign): array
    {
        $attestedUserIds = $campaign->attestations()->pluck('user_id');

        return $campaign->responses()
            ->whereNotIn('status', ['Submitted', 'Accepted'])
            ->whereNotIn('respondent_id', $attestedUserIds)
            ->with('respondent:id,name,email')
            ->get()
            ->map(fn ($r) => [
                'response_id' => $r->id,
                'user' => $r->respondent?->only(['id', 'name', 'email']),
            ])
            ->values()
            ->all();
    }

    /**
     * The campaign's attestable subject, from its scope definition. Shared
     * by the web controller, the offline sync engine (15.1) and the USSD
     * flow (15.4) so every channel signs the same registry of types.
     */
    public function resolveSubject(CsaCampaign $campaign): ?Model
    {
        $type = $campaign->scope_definition['attestable_type'] ?? null;
        $id = $campaign->scope_definition['attestable_id'] ?? null;
        $class = AttestationController::ATTESTABLES[$type] ?? null;

        return $class && $id ? $class::find($id) : null;
    }

    private function snapshotText(Model $attestable): string
    {
        foreach (['attestation_text', 'body', 'content', 'description', 'objective'] as $field) {
            $value = $attestable->getAttribute($field);

            if (is_string($value) && trim($value) !== '') {
                $title = $attestable->getAttribute('title') ?? $attestable->getAttribute('name');

                return trim(($title ? $title."\n\n" : '').$value);
            }
        }

        return (string) json_encode($attestable->only(array_keys($attestable->getAttributes())));
    }

    private function versionOf(Model $attestable): ?string
    {
        foreach (['version_no', 'current_version', 'version'] as $field) {
            if ($attestable->getAttribute($field) !== null) {
                return (string) $attestable->getAttribute($field);
            }
        }

        return null;
    }
}
