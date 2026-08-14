<?php

namespace App\Services;

use App\Models\AssuranceActivity;
use App\Models\AssuranceProvider;
use App\Models\ControlException;
use App\Models\Risk;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The combined assurance map (17.4) — the King IV/V and NCCG concept.
 *
 * The map answers two questions a board actually asks. Where is a
 * significant risk carrying no independent assurance at all? And where are
 * three providers testing the same control, which is duplicated cost
 * rather than triplicated comfort?
 *
 * Both answers depend on thresholds that are configuration, not code:
 * which rating bands count as "significant" and how many providers on one
 * subject counts as duplication are tenant settings with documented
 * defaults (R1).
 */
class AssuranceService
{
    /** Rating bands a board treats as significant, absent tenant override. */
    public const DEFAULT_SIGNIFICANT_BANDS = ['High', 'Critical'];

    /** Providers on one subject before it reads as duplication. */
    public const DEFAULT_DUPLICATION_THRESHOLD = 3;

    public function __construct(private LinkageService $linkage) {}

    // ── Configuration ────────────────────────────────────────────────

    /** @return array{significant_bands: array<int, string>, duplication_threshold: int} */
    public function config(?int $tenantId): array
    {
        $settings = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['assurance'] ?? [])
            : [];

        return [
            'significant_bands' => $settings['significant_bands'] ?? self::DEFAULT_SIGNIFICANT_BANDS,
            'duplication_threshold' => (int) ($settings['duplication_threshold'] ?? self::DEFAULT_DUPLICATION_THRESHOLD),
        ];
    }

    // ── The map ──────────────────────────────────────────────────────

    /**
     * Risk × provider × coverage, plus the gaps and the duplication.
     *
     * Built from three queries regardless of how many risks are in the
     * register — a board-level page cannot afford a query per row (R6).
     *
     * @return array<string, mixed>
     */
    public function map(?int $tenantId = null, ?string $periodLabel = null, ?int $entityId = null): array
    {
        $tenantId ??= auth()->user()?->tenant_id;
        $config = $this->config($tenantId);

        $risks = Risk::query()
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->with('owner:id,name')
            ->orderBy('code')
            ->get(['id', 'code', 'title', 'current_rating_band', 'risk_owner_id', 'entity_id']);

        $providers = AssuranceProvider::query()->active()->orderBy('line')->orderBy('name')->get();

        $activities = AssuranceActivity::query()
            ->when($periodLabel, fn ($q, $p) => $q->where('period_label', $p))
            ->covering()
            ->get()
            ->groupBy(fn (AssuranceActivity $activity) => "{$activity->subject_type}:{$activity->subject_id}");

        // Controls mapped to each risk, so assurance recorded against a
        // control counts as assurance over the risk it mitigates.
        $controlsByRisk = $this->linkage->neighbourIds('risk', $risks->pluck('id')->all(), 'control')
            ?: $this->controlsByRiskFromMap($risks->pluck('id')->all());

        $rows = $risks->map(function (Risk $risk) use ($activities, $providers, $controlsByRisk, $config) {
            $keys = ["risk:{$risk->id}"];

            foreach ($controlsByRisk[$risk->id] ?? [] as $controlId) {
                $keys[] = "control:{$controlId}";
            }

            $covering = collect($keys)
                ->flatMap(fn (string $key) => $activities->get($key, collect()))
                ->values();

            // The band, and only the band. `residual_rating` and
            // `inherent_rating` are numeric scores, not band names, so
            // falling back to them would silently compare 12 against
            // ['High', 'Critical'] and quietly find nothing significant. A
            // risk that has never been rated is not known to be significant,
            // and is reported as unrated rather than assumed either way.
            $band = $risk->current_rating_band;
            $isSignificant = $band !== null && in_array($band, $config['significant_bands'], true);

            $byProvider = $covering->groupBy('provider_id');
            $independent = $covering->filter(function (AssuranceActivity $activity) use ($providers) {
                $provider = $providers->firstWhere('id', $activity->provider_id);

                return $provider?->countsAsIndependent() && ! $activity->isStale();
            });

            return [
                'id' => $risk->id,
                'code' => $risk->code,
                'title' => $risk->title,
                'band' => $band,
                'owner' => $risk->owner?->name,
                'is_significant' => $isSignificant,
                'provider_count' => $byProvider->count(),
                'independent_count' => $independent->count(),
                'stale_count' => $covering->filter(fn (AssuranceActivity $a) => $a->isStale())->count(),
                'cells' => $providers->mapWithKeys(function (AssuranceProvider $provider) use ($byProvider) {
                    $activity = $byProvider->get($provider->id)?->sortByDesc('last_assurance_date')->first();

                    return [$provider->id => $activity ? [
                        'id' => $activity->id,
                        'coverage_level' => $activity->coverage_level,
                        'assurance_type' => $activity->assurance_type,
                        'conclusion' => $activity->conclusion,
                        'reliance_level' => $activity->reliance_level,
                        'last_assurance_date' => $activity->last_assurance_date?->toDateString(),
                        'is_stale' => $activity->isStale(),
                        'source' => $activity->source,
                    ] : null];
                })->all(),
            ];
        });

        return [
            'providers' => $providers->map(fn (AssuranceProvider $provider) => [
                'id' => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
                'line' => $provider->line,
                'line_label' => $provider->lineLabel(),
                'independent' => $provider->countsAsIndependent(),
                'source' => $provider->integration_config_id ? 'integration' : 'manual',
            ])->values()->all(),
            'rows' => $rows->values()->all(),
            'gaps' => $this->gaps($rows),
            'duplication' => $this->duplication($periodLabel, $config['duplication_threshold'], $providers),
            'config' => $config,
            'period_label' => $periodLabel,
        ];
    }

    /**
     * Significant risks with no live independent assurance. Coverage by
     * management alone is not a gap in the sense of "nobody is looking",
     * but it is a gap in the sense the board cares about, so it is
     * reported with the distinction intact.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function gaps(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $row) => $row['is_significant'] && $row['independent_count'] === 0)
            ->map(fn (array $row) => [
                'risk_id' => $row['id'],
                'code' => $row['code'],
                'title' => $row['title'],
                'band' => $row['band'],
                'owner' => $row['owner'],
                'reason' => $row['provider_count'] === 0
                    ? 'No assurance provider covers this risk.'
                    : ($row['stale_count'] > 0
                        ? 'Only non-independent or out-of-date assurance covers this risk.'
                        : 'Only non-independent assurance covers this risk.'),
            ])
            ->values()
            ->all();
    }

    /**
     * Subjects covered by at least the duplication threshold of distinct
     * providers — the "three people testing the same control" case.
     *
     * @param  EloquentCollection<int, AssuranceProvider>  $providers
     * @return array<int, array<string, mixed>>
     */
    public function duplication(?string $periodLabel, int $threshold, ?EloquentCollection $providers = null): array
    {
        $providers ??= AssuranceProvider::query()->active()->get();

        $duplicated = AssuranceActivity::query()
            ->when($periodLabel, fn ($q, $p) => $q->where('period_label', $p))
            ->covering()
            // Self-assessment is management asserting, not a third party
            // testing. Counting it would flag the ordinary three-lines
            // pattern — management self-assesses, second line tests,
            // internal audit audits — as duplication on every single risk,
            // which is the healthy shape rather than wasted effort. What
            // this looks for is three parties independently *testing* the
            // same thing.
            ->where('assurance_type', '!=', 'self_assessment')
            ->get()
            ->groupBy(fn (AssuranceActivity $activity) => "{$activity->subject_type}:{$activity->subject_id}")
            ->filter(fn ($activities) => $activities->pluck('provider_id')->unique()->count() >= $threshold);

        // Subject labels resolve in one query per type for the whole list,
        // never one per row (R6).
        $resolved = $this->resolveSubjects($duplicated->keys()->all());
        $providerNames = $providers->pluck('name', 'id');

        return $duplicated
            ->map(function ($activities, string $key) use ($resolved, $providerNames) {
                [$type, $id] = explode(':', $key);
                $subject = $resolved[$key] ?? null;
                $providerIds = $activities->pluck('provider_id')->unique();

                return [
                    'subject_type' => $type,
                    'subject_label' => AssuranceActivity::SUBJECT_LABELS[$type] ?? $type,
                    'subject_id' => (int) $id,
                    'ref' => $subject['ref'] ?? null,
                    'title' => $subject['title'] ?? '(removed record)',
                    'provider_count' => $providerIds->count(),
                    'providers' => $providerIds
                        ->map(fn ($providerId) => $providerNames->get($providerId))
                        ->filter()
                        ->values()
                        ->all(),
                    'full_coverage_count' => $activities->where('coverage_level', 'full')->count(),
                ];
            })
            ->sortByDesc('provider_count')
            ->values()
            ->all();
    }

    /**
     * Resolve many subjects at once — one query per subject type for the
     * whole list, never one per subject.
     *
     * @param  array<int, string>  $keys  "type:id"
     * @return array<string, array{ref: string|null, title: string|null}>
     */
    private function resolveSubjects(array $keys): array
    {
        $byType = [];

        foreach ($keys as $key) {
            [$type, $id] = explode(':', $key);
            $byType[$type][] = (int) $id;
        }

        $resolved = [];

        foreach ($byType as $type => $ids) {
            $modelClass = AssuranceActivity::modelClassFor($type);

            if (! $modelClass) {
                continue;
            }

            [$refColumn, $titleColumn] = match ($type) {
                'risk' => ['code', 'title'],
                'control' => ['control_ref', 'title'],
                'obligation' => ['obligation_ref', 'title'],
                'process' => ['code', 'name'],
                default => [null, null],
            };

            if (! $refColumn) {
                continue;
            }

            $modelClass::query()
                ->whereIn('id', array_unique($ids))
                ->get(['id', $refColumn, $titleColumn])
                ->each(function ($record) use (&$resolved, $type, $refColumn, $titleColumn) {
                    $resolved["{$type}:{$record->id}"] = [
                        'ref' => $record->{$refColumn},
                        'title' => $record->{$titleColumn},
                    ];
                });
        }

        return $resolved;
    }

    /**
     * Fallback when risk↔control links are held in the hard pivot rather
     * than the linkage graph — which is the normal case, since Phase 2
     * predates the graph.
     *
     * @param  array<int, int>  $riskIds
     * @return array<int, array<int, int>>
     */
    private function controlsByRiskFromMap(array $riskIds): array
    {
        if ($riskIds === []) {
            return [];
        }

        return DB::table('risk_control_map')
            ->whereIn('risk_id', $riskIds)
            ->get(['risk_id', 'control_id'])
            ->groupBy('risk_id')
            ->map(fn ($rows) => $rows->pluck('control_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    // ── Reliance ─────────────────────────────────────────────────────

    /**
     * Record how far the board may rely on a provider's work. A named act
     * by a named person, because a combined assurance report is signed on
     * the strength of exactly these decisions.
     */
    public function recordReliance(AssuranceActivity $activity, User $user, array $data): AssuranceActivity
    {
        if (! in_array($data['reliance_level'], AssuranceActivity::RELIANCE_LEVELS, true)) {
            throw ValidationException::withMessages([
                'reliance_level' => "'{$data['reliance_level']}' is not a reliance level.",
            ]);
        }

        // Placing reliance on work that reached no conclusion is not a
        // judgement, it is a hope.
        if ($data['reliance_level'] !== 'none' && $activity->conclusion === 'not_concluded') {
            throw ValidationException::withMessages([
                'reliance_level' => 'Reliance cannot be placed on assurance work that has not concluded.',
            ]);
        }

        $before = ['reliance_level' => $activity->reliance_level];

        $activity->update([
            'reliance_level' => $data['reliance_level'],
            'reliance_rationale' => $data['reliance_rationale'] ?? null,
            'reliance_recorded_by' => $user->id,
            'reliance_recorded_at' => now(),
        ]);

        $activity->auditAction('reliance-recorded', $before, [
            'reliance_level' => $activity->reliance_level,
            'by' => $user->id,
        ]);

        return $activity->fresh();
    }

    // ── Gap escalation ───────────────────────────────────────────────

    /**
     * Nightly: a significant risk with no independent assurance is raised
     * as an exception, so an assurance gap sits in the same queue as every
     * other control failure rather than in a slide nobody actions.
     *
     * @return array{gaps: int, raised: int}
     */
    public function escalateGaps(?int $tenantId = null, ?string $periodLabel = null): array
    {
        $map = $this->map($tenantId, $periodLabel);
        $raised = 0;

        foreach ($map['gaps'] as $gap) {
            $existing = ControlException::withoutGlobalScopes()
                ->where('source_type', 'Assurance')
                ->where('source_id', $gap['risk_id'])
                ->whereNotIn('status', ['Closed', 'Cancelled'])
                ->exists();

            if ($existing) {
                continue;
            }

            try {
                ControlException::create([
                    'tenant_id' => $tenantId ?? auth()->user()?->tenant_id,
                    'reference' => ControlException::nextReference('EXC'),
                    'source_type' => 'Assurance',
                    'source_id' => $gap['risk_id'],
                    'risk_id' => $gap['risk_id'],
                    'title' => "Assurance gap — {$gap['code']}",
                    'description' => "{$gap['title']} is rated {$gap['band']} and carries no live independent assurance. "
                        .$gap['reason'],
                    'severity' => $gap['band'] === 'Critical' ? 'High' : 'Medium',
                    'date_raised' => now()->toDateString(),
                    'target_closure_date' => now()->addDays(90)->toDateString(),
                    'status' => 'Open',
                ]);

                $raised++;
            } catch (\Throwable $e) {
                Log::error('Assurance gap could not be raised as an exception', [
                    'risk' => $gap['risk_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['gaps' => count($map['gaps']), 'raised' => $raised];
    }

    // ── Integration interlock (17.5) ─────────────────────────────────

    /**
     * Apply an assurance record received from third line over /api/v1.
     *
     * The provider is matched by code and must already exist — an inbound
     * payload never conjures an assurance provider into the register, which
     * would let an integration key manufacture board-level comfort. The
     * reliance decision it carries is preserved, attributed to the sending
     * system, and marked `source = 'integration'` so the map can always
     * show where a cell came from.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyInboundActivity(int $tenantId, array $payload): AssuranceActivity
    {
        $provider = AssuranceProvider::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $payload['provider_code'])
            ->first();

        if (! $provider) {
            throw ValidationException::withMessages([
                'provider_code' => "No assurance provider is registered with code '{$payload['provider_code']}'.",
            ]);
        }

        $subjectType = $payload['subject_type'];

        if (! AssuranceActivity::modelClassFor($subjectType)) {
            throw ValidationException::withMessages([
                'subject_type' => "'{$subjectType}' is not an assurable subject type.",
            ]);
        }

        $subjectId = $this->resolveSubjectId($tenantId, $subjectType, $payload);

        if (! $subjectId) {
            throw ValidationException::withMessages([
                'subject_ref' => "No {$subjectType} matches '".($payload['subject_ref'] ?? $payload['subject_id'] ?? '')."'.",
            ]);
        }

        $activity = AssuranceActivity::withoutGlobalScopes()->updateOrCreate(
            [
                'provider_id' => $provider->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'period_label' => $payload['period_label'] ?? null,
            ],
            [
                'tenant_id' => $tenantId,
                'activity_name' => $payload['activity_name'] ?? null,
                'coverage_level' => $payload['coverage_level'] ?? 'partial',
                'assurance_type' => $payload['assurance_type'] ?? 'limited',
                'last_assurance_date' => $payload['last_assurance_date'] ?? null,
                'next_assurance_date' => $payload['next_assurance_date'] ?? null,
                'conclusion' => $payload['conclusion'] ?? 'not_concluded',
                'reliance_level' => $payload['reliance_level'] ?? 'none',
                'reliance_rationale' => $payload['reliance_rationale'] ?? null,
                'scope_note' => $payload['scope_note'] ?? null,
                'source' => 'integration',
                'external_ref' => $payload['external_ref'] ?? null,
                'synced_at' => now(),
            ],
        );

        $activity->auditAction('synced-from-integration', null, [
            'provider' => $provider->code,
            'external_ref' => $activity->external_ref,
            'reliance_level' => $activity->reliance_level,
        ]);

        return $activity->fresh();
    }

    private function resolveSubjectId(int $tenantId, string $type, array $payload): ?int
    {
        if (isset($payload['subject_id'])) {
            return (int) $payload['subject_id'];
        }

        $ref = $payload['subject_ref'] ?? null;

        if (! $ref) {
            return null;
        }

        $modelClass = AssuranceActivity::modelClassFor($type);
        $column = match ($type) {
            'risk', 'process' => 'code',
            'control' => 'control_ref',
            'obligation' => 'obligation_ref',
            default => null,
        };

        if (! $column) {
            return null;
        }

        return $modelClass::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where($column, $ref)
            ->value('id');
    }

    /**
     * The outbound half of the interlock: second-line coverage third line
     * can rely on, shaped for /api/v1 (17.5).
     *
     * @return array<int, array<string, mixed>>
     */
    public function coverageForExport(int $tenantId, ?string $since = null, ?string $periodLabel = null): array
    {
        $activities = AssuranceActivity::withoutGlobalScopes()
            ->where('assurance_activities.tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->when($since, fn ($q, $s) => $q->where('updated_at', '>=', $s))
            ->when($periodLabel, fn ($q, $p) => $q->where('period_label', $p))
            ->with(['provider:id,code,name,line', 'relianceRecorder:id,name'])
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        // One query per subject type for the whole payload, not one per row
        // — this endpoint returns up to 500 activities (R6).
        $subjects = $this->resolveSubjects(
            $activities->map(fn (AssuranceActivity $a) => "{$a->subject_type}:{$a->subject_id}")
                ->unique()
                ->values()
                ->all()
        );

        return $activities
            ->map(fn (AssuranceActivity $activity) => [
                'id' => $activity->id,
                'provider_code' => $activity->provider?->code,
                'provider_name' => $activity->provider?->name,
                'provider_line' => $activity->provider?->line,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'subject_ref' => $subjects["{$activity->subject_type}:{$activity->subject_id}"]['ref'] ?? null,
                'activity_name' => $activity->activity_name,
                'coverage_level' => $activity->coverage_level,
                'assurance_type' => $activity->assurance_type,
                'period_label' => $activity->period_label,
                'last_assurance_date' => $activity->last_assurance_date?->toDateString(),
                'next_assurance_date' => $activity->next_assurance_date?->toDateString(),
                'conclusion' => $activity->conclusion,
                'reliance_level' => $activity->reliance_level,
                'reliance_rationale' => $activity->reliance_rationale,
                'reliance_recorded_by' => $activity->relianceRecorder?->name,
                'reliance_recorded_at' => $activity->reliance_recorded_at?->toIso8601String(),
                'is_stale' => $activity->isStale(),
                'source' => $activity->source,
                'external_ref' => $activity->external_ref,
                'updated_at' => $activity->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Headline numbers for the assurance map page.
     *
     * The map is passed in rather than recomputed: the page needs both, and
     * building it twice would double every query on a board-level view (R6).
     *
     * @param  array<string, mixed>|null  $map  a map() result to derive from
     */
    public function stats(?int $tenantId = null, ?string $periodLabel = null, ?array $map = null): array
    {
        $map ??= $this->map($tenantId, $periodLabel);

        return [
            'providers' => count($map['providers']),
            'subjects_covered' => AssuranceActivity::covering()
                ->when($periodLabel, fn ($q, $p) => $q->where('period_label', $p))
                ->distinct()
                ->count('subject_id'),
            'gaps' => count($map['gaps']),
            'duplication' => count($map['duplication']),
            'stale' => collect($map['rows'])->sum('stale_count'),
        ];
    }
}
