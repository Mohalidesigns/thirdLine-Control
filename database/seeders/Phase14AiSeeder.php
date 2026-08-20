<?php

namespace Database\Seeders;

use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\BudgetService;
use App\Services\Ai\CapabilityRegistry;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * A demoable AI layer for the seeded Nigerian dataset.
 *
 * Two decisions worth stating.
 *
 * First, capabilities are seeded ENABLED here, which is the opposite of the
 * production default. AiGateway treats a missing configuration row as off,
 * so a real installation ships dormant and an administrator turns each
 * capability on deliberately. A demo database with everything off shows
 * nothing, so the seeder does what an administrator would do on day one —
 * and does it as an audited act with a named approver, exactly as the
 * governance page would.
 *
 * Second, the demo interactions below are written directly rather than
 * produced by calling Ollama. Seeding must run without a model server, and
 * a seeder that waits on live generations is a seeder nobody runs twice.
 * They carry realistic token counts, costs, latencies and — importantly — a
 * spread of human decisions, including a rejection with a reason, so the
 * acceptance-rate panel on the governance page has something honest to show.
 */
class Phase14AiSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            return;
        }

        $admin = User::where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('name', 'System Administrator'))->first()
            ?? User::where('tenant_id', $tenant->id)->first();

        if (! $admin) {
            return;
        }

        $this->enableCapabilities($tenant->id, $admin);
        $this->seedBudget($tenant->id);
        $this->buildIndex();
        $this->seedInteractions($tenant->id);
    }

    /**
     * Turn every capability on for the demo tenant.
     *
     * Note the approval-role narrowing on the two capabilities whose output
     * carries the most weight: a drafted control becomes part of the control
     * environment, and a parsed circular becomes an obligation the bank
     * believes it owes. Restricting who may accept those is exactly the kind
     * of tightening ai_configurations exists for — it never opens a gate,
     * only closes one further (R2).
     */
    private function enableCapabilities(int $tenantId, User $admin): void
    {
        $headRoleId = Role::where('name', 'Control Function Head')->value('id');

        $restricted = ['control.draft', 'regulatory.parse', 'framework.map'];

        foreach (CapabilityRegistry::keys() as $key) {
            AiConfiguration::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'capability_key' => $key],
                [
                    'is_enabled' => true,
                    // Null model: the capability's configured tier applies,
                    // so an installation that changes OLLAMA_MODEL does
                    // not have to revisit nine rows.
                    'model' => null,
                    'requires_approval_role_id' => in_array($key, $restricted, true) ? $headRoleId : null,
                    'min_confidence_to_surface' => 0.35,
                    'created_by' => $admin->id,
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                ],
            );
        }
    }

    private function seedBudget(int $tenantId): void
    {
        $budget = app(BudgetService::class)->current($tenantId);

        // Around a fifth consumed, so the governance page shows a live gauge
        // rather than an empty one, and well clear of any alert threshold.
        $budget->update([
            'tokens_used' => (int) round($budget->token_budget * 0.18),
            'cost_used_minor' => (int) round($budget->cost_budget_minor * 0.21),
        ]);
    }

    /**
     * Build the retrieval index over whatever the earlier phase seeders
     * created. Without this the demo Atlas answers "I don't have that" to
     * everything, which is correct behaviour and a terrible first impression.
     */
    private function buildIndex(): void
    {
        $indexer = app(KnowledgeIndexer::class);

        foreach (KnowledgeIndexer::sourceMap() as $class) {
            $class::withoutGlobalScopes()
                ->limit(400)
                ->get()
                ->each(fn ($record) => $indexer->index($record));
        }
    }

    /**
     * A handful of interactions across three outcomes — accepted, edited and
     * rejected — so the acceptance rate, the override rate and the rejection
     * categories all render with real numbers.
     */
    private function seedInteractions(int $tenantId): void
    {
        if (AiInteraction::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $officer = User::where('tenant_id', $tenantId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Control Officer'))->first();
        $head = User::where('tenant_id', $tenantId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Control Function Head'))->first();

        if (! $officer || ! $head) {
            return;
        }

        $control = Control::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $exception = ControlException::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        $promptVersion = 1;
        $model = (string) config('services.ollama.default_model');

        $rows = [
            [
                'capability_key' => 'exception.triage',
                'user_id' => $officer->id,
                'subject_type' => $exception?->getMorphClass(),
                'subject_id' => $exception?->getKey(),
                'status' => 'Completed',
                'human_decision' => 'Accepted',
                'decided_by' => $officer->id,
                'confidence' => 0.780,
                'input_tokens' => 3120,
                'output_tokens' => 640,
                'latency_ms' => 4210,
                'parsed_output' => [
                    'category' => 'Process design',
                    'probable_root_cause' => 'The reconciliation has no named deputy, so it lapses whenever the branch accountant is on leave.',
                    'root_cause_confidence' => 0.72,
                    'recurrence_risk' => 'High',
                    'confidence' => 0.78,
                ],
                'created_at' => now()->subDays(9),
            ],
            [
                'capability_key' => 'control.draft',
                'user_id' => $officer->id,
                'subject_type' => null,
                'subject_id' => null,
                'status' => 'Completed',
                // Edited, not Accepted: the honest common case, and the one
                // the override-rate panel exists to surface.
                'human_decision' => 'Edited',
                'decided_by' => $head->id,
                'confidence' => 0.640,
                'input_tokens' => 5880,
                'output_tokens' => 1420,
                'latency_ms' => 9870,
                'parsed_output' => [
                    'title' => 'Dual authorisation of outward NIP transfers above ₦5m',
                    'type' => 'Preventive',
                    'nature' => 'IT-Dependent Manual',
                    'frequency' => 'Daily',
                    'confidence' => 0.64,
                ],
                'edit_diff' => [
                    'frequency' => ['from' => 'Daily', 'to' => 'Event-driven'],
                ],
                'created_at' => now()->subDays(6),
            ],
            [
                'capability_key' => 'risk.intelligence',
                'user_id' => $officer->id,
                'status' => 'Completed',
                'human_decision' => 'Rejected',
                'decided_by' => $officer->id,
                'rejection_reason' => 'Restated three risks already in the register under slightly different wording.',
                'confidence' => 0.410,
                'input_tokens' => 7240,
                'output_tokens' => 1980,
                'latency_ms' => 11300,
                'parsed_output' => ['risk_statements' => [], 'confidence' => 0.41],
                'created_at' => now()->subDays(4),
            ],
            [
                'capability_key' => 'atlas.chat',
                'user_id' => $head->id,
                'status' => 'Completed',
                'human_decision' => 'Pending',
                'confidence' => 0.860,
                'input_tokens' => 4410,
                'output_tokens' => 380,
                'latency_ms' => 2980,
                'parsed_output' => [
                    'answer' => 'Four key controls have no test instance recorded for the current quarter.',
                    'confidence' => 0.86,
                    'insufficient_context' => false,
                ],
                'created_at' => now()->subDays(2),
            ],
            [
                // A refusal, recorded. "The model was never asked" is a fact
                // a governance review needs to be able to see (R3).
                'capability_key' => 'regulatory.parse',
                'user_id' => $officer->id,
                'status' => 'Rate Limited',
                'human_decision' => 'Pending',
                'error_message' => 'You have used this assistant too many times in the last minute. Please wait a moment.',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'latency_ms' => 0,
                'created_at' => now()->subDay(),
            ],
        ];

        $budgetService = app(BudgetService::class);

        foreach ($rows as $row) {
            $citations = ($row['status'] === 'Completed' && $control)
                ? [[
                    'record_type' => 'control',
                    'record_id' => $control->id,
                    'label' => 'Control',
                    'reference' => $control->control_ref,
                    'title' => $control->title,
                    'route' => 'controls.show',
                    'claim' => 'Referenced in the drafted response.',
                ]]
                : [];

            AiInteraction::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'prompt_version' => $promptVersion,
                'model' => $model,
                'currency' => (string) config('services.ollama.pricing_currency', 'USD'),
                'cost_minor' => $budgetService->costMinor(
                    $model,
                    (int) ($row['input_tokens'] ?? 0),
                    (int) ($row['output_tokens'] ?? 0),
                ),
                'citations' => $citations,
                'retrieved_context' => $citations === [] ? [] : [[
                    'type' => 'control', 'id' => $control->id, 'chunk' => 0, 'score' => 1.0,
                ]],
                'decided_at' => ($row['human_decision'] ?? 'Pending') !== 'Pending' ? now()->subDays(1) : null,
                ...$row,
            ]);
        }
    }
}
