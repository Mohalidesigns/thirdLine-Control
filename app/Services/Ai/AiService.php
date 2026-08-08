<?php

namespace App\Services\Ai;

use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\Capabilities\AtlasCapability;
use App\Services\Ai\Capabilities\Capability;
use App\Services\Ai\Capabilities\ControlDraftCapability;
use App\Services\Ai\Capabilities\EvidenceReviewCapability;
use App\Services\Ai\Capabilities\ExceptionTriageCapability;
use App\Services\Ai\Capabilities\FrameworkMapCapability;
use App\Services\Ai\Capabilities\NarrativeCapability;
use App\Services\Ai\Capabilities\RegulatoryParseCapability;
use App\Services\Ai\Capabilities\RiskIntelligenceCapability;
use App\Services\Ai\Capabilities\VendorScreenCapability;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use App\Services\Ai\Exceptions\CapabilityDisabledException;
use Illuminate\Database\Eloquent\Model;

/**
 * What controllers talk to. Resolves a capability by key, runs it through
 * the gateway, and mediates the accept / edit / reject decisions.
 *
 * Nothing here bypasses AiGateway, and nothing here can apply a draft
 * without an AcceptedDraft, so the thin layer stays thin: it routes, it does
 * not decide.
 */
class AiService
{
    /** @var array<string, class-string<Capability>> */
    private const CAPABILITIES = [
        'control.draft' => ControlDraftCapability::class,
        'regulatory.parse' => RegulatoryParseCapability::class,
        'risk.intelligence' => RiskIntelligenceCapability::class,
        'evidence.review' => EvidenceReviewCapability::class,
        'exception.triage' => ExceptionTriageCapability::class,
        'narrative.generate' => NarrativeCapability::class,
        'framework.map' => FrameworkMapCapability::class,
        'vendor.screen' => VendorScreenCapability::class,
        'atlas.chat' => AtlasCapability::class,
    ];

    public function capability(string $key): Capability
    {
        if (! isset(self::CAPABILITIES[$key])) {
            throw new CapabilityDisabledException("Unknown AI capability '{$key}'.");
        }

        return app(self::CAPABILITIES[$key]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(string $key, User $user, array $input, ?Model $subject = null): AiDraft
    {
        return $this->capability($key)->run($user, $input, $subject);
    }

    /**
     * Accept a draft and, where the capability creates something, apply it.
     *
     * The order is deliberate: AiDraft::accept() records the human decision
     * first and returns the AcceptedDraft, and only then can apply() run.
     * A capability cannot see a payload before a person has signed for it.
     *
     * @param  array<string, mixed>  $edits
     * @return array{interaction: AiInteraction, applied: Model|null}
     */
    public function accept(AiInteraction $interaction, User $approver, array $edits = []): array
    {
        $capability = $this->capability($interaction->capability_key);

        $this->assertApprovalRole($interaction, $approver);

        $accepted = AiDraft::fromInteraction($interaction)->accept($approver, $edits);

        $applied = $capability->isApplicable()
            ? $capability->apply($accepted, $approver)
            : null;

        return ['interaction' => $interaction->refresh(), 'applied' => $applied];
    }

    public function reject(AiInteraction $interaction, User $user, string $reason, ?string $category = null): AiInteraction
    {
        return AiDraft::fromInteraction($interaction)->reject($user, $reason, $category);
    }

    /**
     * Where a tenant has narrowed who may accept a capability's output, that
     * role is required IN ADDITION to the capability's own permission —
     * never instead of it. A configuration row can tighten the gate; it can
     * never open one (R2).
     */
    private function assertApprovalRole(AiInteraction $interaction, User $approver): void
    {
        $configuration = AiConfiguration::withoutGlobalScopes()
            ->where('tenant_id', $interaction->tenant_id)
            ->where('capability_key', $interaction->capability_key)
            ->with('approvalRole')
            ->first();

        $role = $configuration?->approvalRole;

        if ($role && ! $approver->hasRole($role->name)) {
            throw new AiDecisionRequiredException(sprintf(
                'Accepting a suggestion from this assistant is restricted to the %s role.',
                $role->name,
            ));
        }
    }

    /**
     * What the UI may offer this user. A capability appears only when it is
     * enabled for the tenant AND the user holds the underlying domain
     * permission — the affordance and the gate come from the same source, so
     * a button never appears for something the request would refuse.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableTo(User $user): array
    {
        $enabled = AiConfiguration::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_enabled', true)
            ->pluck('capability_key')
            ->all();

        $available = [];

        foreach (CapabilityRegistry::all() as $key => $definition) {
            if (! in_array($key, $enabled, true)) {
                continue;
            }

            if (filled($definition['permission'] ?? null) && ! $user->can($definition['permission'])) {
                continue;
            }

            $available[] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'description' => $definition['description'] ?? null,
                'applicable' => $this->capability($key)->isApplicable(),
            ];
        }

        return $available;
    }

    public function isAvailable(string $key, User $user): bool
    {
        return collect($this->availableTo($user))->contains('key', $key);
    }
}
