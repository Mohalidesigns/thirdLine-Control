<?php

namespace App\Services\Ai\Capabilities;

use App\Models\Complaint;
use App\Models\ControlException;
use App\Models\Incident;
use App\Models\Risk;
use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\CapabilityRequest;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §C.6 #3 — risk intelligence.
 *
 * Reads what actually went wrong — incidents, complaints, recurring
 * exceptions — and drafts the risk statements a register usually acquires
 * only after someone has time to write them up.
 *
 * Applying creates risks in Draft with no scores. A risk's likelihood and
 * impact are a judgement the second line owns and the appetite framework
 * measures against; a model inventing a 4×5 would put a number the board
 * will read next to no reasoning anyone can defend.
 */
class RiskIntelligenceCapability extends Capability
{
    public function key(): string
    {
        return 'risk.intelligence';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $signals = $this->signals($user, $subject);

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'focus' => (string) ($input['focus'] ?? 'the operational risk profile'),
                'recent_incidents' => $signals['incidents'],
                'recent_complaints' => $signals['complaints'],
                'recurring_exceptions' => $signals['exceptions'],
                'existing_risk_titles' => $signals['existing'],
                'basel_event_types' => Risk::BASEL_EVENT_TYPES,
            ],
            subject: $subject,
            retrievalQuery: (string) ($input['focus'] ?? '')
                ?: implode(' ', array_column($signals['incidents'], 'title')),
        );
    }

    public function isApplicable(): bool
    {
        return true;
    }

    public function apply(AcceptedDraft $draft, User $approver): Model
    {
        $statements = array_values(array_filter(
            (array) $draft->get('risk_statements', []),
            fn ($s) => is_array($s) && filled($s['title'] ?? null),
        ));

        if ($statements === []) {
            throw new AiDecisionRequiredException(
                'This draft contains no risk statement to add to the register.',
            );
        }

        return DB::transaction(function () use ($statements, $draft, $approver) {
            $first = null;

            foreach ($statements as $statement) {
                $risk = Risk::create([
                    'tenant_id' => $approver->tenant_id,
                    'code' => Risk::nextReference('RSK', false, 'code'),
                    'title' => $this->fit($statement['title'], 255),
                    // Cause / event / consequence, written out. The register
                    // is read by people who did not attend the workshop.
                    'description' => $this->statement($statement),
                    'category' => $this->fit($statement['category'] ?? null, 100),
                    'basel_event_type' => $this->oneOf(
                        $statement['basel_event_type'] ?? null,
                        Risk::BASEL_EVENT_TYPES,
                        'Execution Delivery & Process Management',
                    ),
                    'source' => 'AI-assisted',
                    // Draft and unscored on purpose — see the class note.
                    'status' => 'Draft',
                ]);

                $risk->auditAction('ai_drafted', null, [
                    ...$draft->provenance(),
                    'suggested_kris' => $statement['suggested_kris'] ?? [],
                ]);

                $first ??= $risk;
            }

            $draft->recordApplication($first);

            return $first;
        });
    }

    /** @param array<string, mixed> $statement */
    private function statement(array $statement): string
    {
        return trim(implode("\n\n", array_filter([
            filled($statement['cause'] ?? null) ? 'Cause: '.$statement['cause'] : null,
            filled($statement['event'] ?? null) ? 'Event: '.$statement['event'] : null,
            filled($statement['consequence'] ?? null) ? 'Consequence: '.$statement['consequence'] : null,
        ]))) ?: (string) ($statement['title'] ?? '');
    }

    /**
     * The evidence the model reasons over. Bounded deliberately: a hundred
     * incidents is not more insight than twenty, it is just a bigger bill
     * and a longer context for the important ones to get lost in.
     *
     * @return array{incidents: array, complaints: array, exceptions: array, existing: array}
     */
    private function signals(User $user, ?Model $subject): array
    {
        $incidents = Incident::query()
            ->when($subject instanceof Incident, fn ($q) => $q->whereKey($subject->getKey()))
            ->latest('occurred_at')
            ->limit(20)
            ->get(['id', 'incident_ref', 'title', 'incident_type', 'basel_event_type', 'root_cause'])
            ->map(fn (Incident $i) => [
                'ref' => $i->incident_ref,
                'title' => $i->title,
                'type' => $i->incident_type,
                'basel' => $i->basel_event_type,
                'root_cause' => $i->root_cause,
            ])->all();

        // Complaint themes only, never a complainant. Customer-level detail
        // is redacted downstream regardless, but not selecting it in the
        // first place is the cheaper control.
        $complaints = $user->can('view complaints')
            ? Complaint::query()
                ->select('category_id')
                ->selectRaw('count(*) as total')
                ->groupBy('category_id')
                ->with('category:id,name')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->map(fn ($row) => ['theme' => $row->category?->name ?? 'Uncategorised', 'count' => (int) $row->total])
                ->all()
            : [];

        $exceptions = ControlException::query()
            ->where('is_recurring', true)
            ->latest('date_raised')
            ->limit(15)
            ->get(['id', 'reference', 'title', 'severity', 'root_cause'])
            ->map(fn (ControlException $e) => [
                'ref' => $e->reference,
                'title' => $e->title,
                'severity' => $e->severity,
                'root_cause' => $e->root_cause,
            ])->all();

        // So the model can flag genuine gaps rather than re-proposing what
        // the register already holds.
        $existing = Risk::query()->orderBy('code')->limit(120)->pluck('title')->all();

        return compact('incidents', 'complaints', 'exceptions', 'existing');
    }
}
