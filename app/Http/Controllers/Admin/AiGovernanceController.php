<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiBudgetRequest;
use App\Http\Requests\AiConfigurationRequest;
use App\Http\Requests\AiPromptRequest;
use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\AiKnowledgeChunk;
use App\Models\AiPrompt;
use App\Services\Ai\BudgetService;
use App\Services\Ai\CapabilityRegistry;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\PromptRegistry;
use App\Services\AuditTrailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → AI (§C.8).
 *
 * This surface exists twice over: it is how an administrator runs the
 * module, and it is the evidence a customer shows its own regulator that
 * the AI in its control environment is governed — human oversight, model
 * registry, versioned prompts, bounded spend, and an honest quality metric.
 * Draft King V's technology chapter and Ghana's 2026 BoG AI/ML directive
 * both ask for exactly that, and being able to print it is a sales asset.
 *
 * The quality metric on this page is the acceptance rate, not a confidence
 * average. Confidence is the model's opinion of itself; the proportion of
 * drafts a person accepted, edited or threw away is what actually happened.
 */
class AiGovernanceController extends Controller
{
    public function __construct(
        private BudgetService $budget,
        private PromptRegistry $prompts,
        private OllamaClient $client,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AiConfiguration::class);

        $tenantId = (int) $request->user()->tenant_id;

        return Inertia::render('Admin/Ai/Index', [
            'capabilities' => $this->capabilities($tenantId),
            'budget' => $this->budgetProps($tenantId),
            'usage' => $this->usage($tenantId),
            'quality' => $this->quality($tenantId),
            'rejections' => $this->rejectionCategories($tenantId),
            'index' => $this->indexHealth($tenantId),
            'models' => [
                'default' => config('services.ollama.default_model'),
                'reasoning' => config('services.ollama.reasoning_model'),
                'fast' => config('services.ollama.fast_model'),
                // Never the key — only whether one exists.
                'configured' => $this->client->configured(),
                'pricing' => config('services.ollama.pricing'),
            ],
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => $request->user()->can('manage ai'),
                'viewLog' => $request->user()->can('view ai-log'),
            ],
        ]);
    }

    /** Enable, disable, re-model or re-budget one capability. */
    public function updateCapability(AiConfigurationRequest $request): RedirectResponse
    {
        $this->authorize('create', AiConfiguration::class);

        $configuration = AiConfiguration::withoutGlobalScopes()->firstOrNew([
            'tenant_id' => $request->user()->tenant_id,
            'capability_key' => $request->string('capability_key')->toString(),
        ]);

        $before = $configuration->exists ? $configuration->only([
            'is_enabled', 'model', 'monthly_token_budget', 'requires_approval_role_id',
        ]) : null;

        $configuration->fill([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
            'created_by' => $configuration->created_by ?? $request->user()->id,
            // Turning a capability on is itself an approval, and the audit
            // trail should name the person who did it.
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        $configuration->auditAction(
            $request->boolean('is_enabled') ? 'ai_capability_enabled' : 'ai_capability_disabled',
            $before,
            $configuration->only(['is_enabled', 'model', 'monthly_token_budget', 'requires_approval_role_id']),
        );

        return back()->with('success', 'AI capability settings saved.');
    }

    public function updateBudget(AiBudgetRequest $request): RedirectResponse
    {
        $this->authorize('manageBudget', AiConfiguration::class);

        $budget = $this->budget->current((int) $request->user()->tenant_id);
        $before = $budget->only(['token_budget', 'cost_budget_minor', 'hard_stop']);

        $budget->update($request->validated());
        $budget->auditAction('ai_budget_changed', $before, $budget->only([
            'token_budget', 'cost_budget_minor', 'hard_stop',
        ]));

        return back()->with('success', 'AI budget updated.');
    }

    /** Prompt version history with diffs (§C.8). */
    public function prompts(Request $request): Response
    {
        $this->authorize('managePrompts', AiConfiguration::class);

        $tenantId = (int) $request->user()->tenant_id;
        $key = (string) ($request->input('key') ?: (CapabilityRegistry::keys()[0] ?? ''));

        $history = $this->prompts->history($key, $tenantId);

        // How many calls actually ran on each version — the number that says
        // whether a prompt change was ever exercised. One grouped query, so
        // the page stays flat however long the history gets.
        $usage = AiInteraction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('prompt_id', $history->pluck('id'))
            ->selectRaw('prompt_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('prompt_id')
            ->pluck('total', 'prompt_id');

        return Inertia::render('Admin/Ai/Prompts', [
            'capabilityKey' => $key,
            'capabilities' => collect(CapabilityRegistry::all())
                ->map(fn ($definition, $capabilityKey) => [
                    'key' => $capabilityKey,
                    'label' => $definition['label'] ?? $capabilityKey,
                ])->values(),
            'versions' => $history->map(fn (AiPrompt $prompt, int $i) => [
                'id' => $prompt->id,
                'version' => $prompt->version,
                'name' => $prompt->name,
                'is_active' => $prompt->is_active,
                'is_shipped' => $prompt->tenant_id === null,
                'model_hint' => $prompt->model_hint,
                'max_tokens' => $prompt->max_tokens,
                'changelog' => $prompt->changelog,
                'system_prompt' => $prompt->system_prompt,
                'user_template' => $prompt->user_template,
                'output_schema' => $prompt->output_schema,
                'created_by' => $prompt->creator?->name,
                'approved_by' => $prompt->approver?->name,
                'approved_at' => $prompt->approved_at?->toIso8601String(),
                'created_at' => $prompt->created_at?->toIso8601String(),
                // Diff against the version immediately below it.
                'diff' => $prompt->diffAgainst($history->get($i + 1)),
                'usage_count' => (int) ($usage[$prompt->id] ?? 0),
            ])->values(),
        ]);
    }

    /** Publish the next version. Never edits an existing one (R3). */
    public function storePrompt(AiPromptRequest $request): RedirectResponse
    {
        $this->authorize('managePrompts', AiConfiguration::class);

        $prompt = $this->prompts->publish(
            $request->string('key')->toString(),
            (int) $request->user()->tenant_id,
            $request->validated(),
            (int) $request->user()->id,
        );

        return back()->with(
            'success',
            "Version {$prompt->version} saved as a draft. Activate it when you are ready for it to run.",
        );
    }

    public function activatePrompt(Request $request, AiPrompt $prompt): RedirectResponse
    {
        $this->authorize('managePrompts', AiConfiguration::class);

        // A shipped-library row belongs to no tenant and must not be flipped
        // by one of them; publishing an override is the supported route.
        abort_unless((int) $prompt->tenant_id === (int) $request->user()->tenant_id, 403);

        $this->prompts->activate($prompt, (int) $request->user()->tenant_id, (int) $request->user()->id);

        return back()->with('success', "Version {$prompt->version} is now live for this capability.");
    }

    /** The exportable AI activity log (§C.8). */
    public function log(Request $request): Response
    {
        $this->authorize('viewAny', AiInteraction::class);

        $interactions = $this->logQuery($request)
            ->with(['user:id,name', 'decider:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AiInteraction $interaction) => [
                'id' => $interaction->id,
                'created_at' => $interaction->created_at?->toIso8601String(),
                'user' => $interaction->user?->name ?? 'System',
                'capability' => $interaction->capability_key,
                'capability_label' => CapabilityRegistry::label($interaction->capability_key),
                'model' => $interaction->model,
                'prompt_version' => $interaction->prompt_version,
                'status' => $interaction->status,
                'decision' => $interaction->human_decision,
                'decided_by' => $interaction->decider?->name,
                'confidence' => $interaction->confidence,
                'tokens' => $interaction->totalTokens(),
                'cost_minor' => $interaction->cost_minor,
                'currency' => $interaction->currency,
                'latency_ms' => $interaction->latency_ms,
                'subject' => $interaction->subject_type
                    ? class_basename($interaction->subject_type).' #'.$interaction->subject_id
                    : null,
                'citations' => count((array) $interaction->citations),
                'context_records' => count((array) $interaction->retrieved_context),
                'error' => $interaction->error_message,
                'rejection_reason' => $interaction->rejection_reason,
            ]);

        return Inertia::render('Admin/Ai/Log', [
            'interactions' => $interactions,
            'filters' => $request->only(['capability', 'status', 'decision', 'user_id', 'from', 'to']),
            'options' => [
                'capabilities' => collect(CapabilityRegistry::all())
                    ->map(fn ($d, $k) => ['value' => $k, 'label' => $d['label'] ?? $k])->values(),
                'statuses' => AiInteraction::STATUSES,
                'decisions' => AiInteraction::DECISIONS,
            ],
            'can' => ['export' => $request->user()->can('export ai-log')],
        ]);
    }

    /**
     * CSV of the activity log.
     *
     * Carries the redacted input and the parsed output, never raw_output:
     * the raw text is the verbatim model reply and the least controlled
     * thing in the table, and an export leaves the platform's boundary by
     * definition (R5). The export is itself audited (R3).
     */
    public function exportLog(Request $request): StreamedResponse
    {
        $this->authorize('export', AiInteraction::class);

        app(AuditTrailService::class)->record('ai_log_exported', $request->user(), null, [
            'filters' => $request->only(['capability', 'status', 'decision', 'user_id', 'from', 'to']),
        ]);

        $query = $this->logQuery($request)->with(['user:id,name', 'decider:id,name'])->orderByDesc('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ID', 'Timestamp', 'User', 'Capability', 'Model', 'Prompt Version',
                'Status', 'Human Decision', 'Decided By', 'Decided At', 'Confidence',
                'Input Tokens', 'Output Tokens', 'Cost (minor)', 'Currency', 'Latency (ms)',
                'Subject', 'Retrieved Records', 'Citations', 'Rejection Reason', 'Error',
                'Redacted Input', 'Parsed Output',
            ]);

            $query->chunk(300, function ($rows) use ($out) {
                foreach ($rows as $interaction) {
                    fputcsv($out, [
                        $interaction->id,
                        $interaction->created_at?->toIso8601String(),
                        $interaction->user?->name ?? 'System',
                        $interaction->capability_key,
                        $interaction->model,
                        $interaction->prompt_version,
                        $interaction->status,
                        $interaction->human_decision,
                        $interaction->decider?->name,
                        $interaction->decided_at?->toIso8601String(),
                        $interaction->confidence,
                        $interaction->input_tokens,
                        $interaction->output_tokens,
                        $interaction->cost_minor,
                        $interaction->currency,
                        $interaction->latency_ms,
                        $interaction->subject_type ? class_basename($interaction->subject_type).' #'.$interaction->subject_id : null,
                        count((array) $interaction->retrieved_context),
                        count((array) $interaction->citations),
                        $interaction->rejection_reason,
                        $interaction->error_message,
                        json_encode($interaction->input_redacted),
                        json_encode($interaction->parsed_output),
                    ]);
                }
            });

            fclose($out);
        }, 'ai-activity-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function logQuery(Request $request)
    {
        return AiInteraction::query()
            ->when($request->capability, fn ($q, $c) => $q->where('capability_key', $c))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->decision, fn ($q, $d) => $q->where('human_decision', $d))
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->from, fn ($q, $f) => $q->whereDate('created_at', '>=', $f))
            ->when($request->to, fn ($q, $t) => $q->whereDate('created_at', '<=', $t));
    }

    /** @return array<int, array<string, mixed>> */
    private function capabilities(int $tenantId): array
    {
        $configured = AiConfiguration::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('capability_key');

        $prompts = AiPrompt::query()
            ->visibleTo($tenantId)
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')
            ->get()
            ->groupBy('key');

        // One grouped count rather than one per capability. Nine extra
        // queries is not a crisis today, but it is an N+1 that grows with the
        // capability list, and it is the page an administrator refreshes.
        $calls = AiInteraction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('capability_key')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('capability_key')
            ->pluck('total', 'capability_key');

        return collect(CapabilityRegistry::all())->map(function (array $definition, string $key) use ($configured, $prompts, $calls) {
            $configuration = $configured->get($key);
            $prompt = $prompts->get($key)?->first();
            $tier = (string) ($definition['tier'] ?? 'default');

            return [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'description' => $definition['description'] ?? null,
                'permission' => $definition['permission'] ?? null,
                'tier' => $tier,
                'is_enabled' => (bool) $configuration?->is_enabled,
                'model' => $configuration?->model
                    ?: $prompt?->model_hint
                    ?: config("services.ollama.{$tier}_model"),
                'model_is_override' => filled($configuration?->model),
                'monthly_token_budget' => $configuration?->monthly_token_budget,
                'requires_approval_role_id' => $configuration?->requires_approval_role_id,
                'min_confidence_to_surface' => $configuration?->min_confidence_to_surface,
                'active_prompt_version' => $prompt?->version,
                'calls_30d' => (int) ($calls[$key] ?? 0),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function budgetProps(int $tenantId): array
    {
        $budget = $this->budget->current($tenantId);

        return [
            'period_label' => $budget->period_label,
            'token_budget' => $budget->token_budget,
            'tokens_used' => $budget->tokens_used,
            'cost_budget_minor' => $budget->cost_budget_minor,
            'cost_used_minor' => $budget->cost_used_minor,
            'currency' => $budget->currency,
            'hard_stop' => $budget->hard_stop,
            'alert_thresholds' => $budget->alert_thresholds,
            'alerted_at' => $budget->alerted_at,
            'token_percent' => $budget->tokenPercentUsed(),
            'cost_percent' => $budget->costPercentUsed(),
            'exhausted' => $budget->isExhausted(),
        ];
    }

    /**
     * Daily spend for the trend. Grouped in SQL rather than in PHP so the
     * page stays flat as the log grows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function usage(int $tenantId): array
    {
        return AiInteraction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(input_tokens + output_tokens) as tokens')
            ->selectRaw('SUM(cost_minor) as cost_minor')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'cost_minor' => (int) $row->cost_minor,
            ])->all();
    }

    /**
     * The honest quality metric (§C.8): what proportion of drafts a person
     * accepted unchanged, edited before accepting, or rejected outright.
     *
     * @return array<int, array<string, mixed>>
     */
    private function quality(int $tenantId): array
    {
        $rows = AiInteraction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'Completed')
            ->selectRaw('capability_key')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN human_decision = 'Accepted' THEN 1 ELSE 0 END) as accepted")
            ->selectRaw("SUM(CASE WHEN human_decision = 'Edited' THEN 1 ELSE 0 END) as edited")
            ->selectRaw("SUM(CASE WHEN human_decision = 'Rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN human_decision = 'Pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw('AVG(confidence) as avg_confidence')
            ->groupBy('capability_key')
            ->get();

        return $rows->map(function ($row) {
            $decided = (int) $row->accepted + (int) $row->edited + (int) $row->rejected;

            return [
                'capability' => $row->capability_key,
                'label' => CapabilityRegistry::label($row->capability_key),
                'total' => (int) $row->total,
                'accepted' => (int) $row->accepted,
                'edited' => (int) $row->edited,
                'rejected' => (int) $row->rejected,
                'pending' => (int) $row->pending,
                // Edited counts as accepted here — the reviewer kept the
                // draft and changed it, which is a success with a caveat,
                // not a failure. The edit rate is reported alongside so the
                // caveat is visible.
                'acceptance_rate' => $decided > 0
                    ? round((((int) $row->accepted + (int) $row->edited) / $decided) * 100, 1)
                    : null,
                'override_rate' => $decided > 0 ? round(((int) $row->edited / $decided) * 100, 1) : null,
                'avg_confidence' => $row->avg_confidence !== null ? round((float) $row->avg_confidence, 3) : null,
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function rejectionCategories(int $tenantId): array
    {
        return DB::table('ai_feedback')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('issue_category')
            ->select('issue_category')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('issue_category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['category' => $row->issue_category, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * Whether retrieval has anything to work with. An assistant answering
     * "I don't have that" to everything is almost always an empty or stale
     * index rather than a broken model, and that is worth showing before an
     * administrator starts changing prompts.
     *
     * @return array<string, mixed>
     */
    private function indexHealth(int $tenantId): array
    {
        $rows = AiKnowledgeChunk::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->selectRaw('source_type')
            ->selectRaw('COUNT(*) as chunks')
            ->selectRaw('SUM(CASE WHEN is_stale = 1 THEN 1 ELSE 0 END) as stale')
            ->groupBy('source_type')
            ->get();

        return [
            'total_chunks' => (int) $rows->sum('chunks'),
            'stale_chunks' => (int) $rows->sum('stale'),
            'by_source' => $rows->map(fn ($row) => [
                'source' => $row->source_type,
                'chunks' => (int) $row->chunks,
                'stale' => (int) $row->stale,
            ])->all(),
        ];
    }
}
