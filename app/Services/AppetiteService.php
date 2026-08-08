<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Risk appetite (10.3). Statements are board artefacts: they are approved
 * by someone other than their author, effective-dated, and never edited in
 * place — a change supersedes the previous version so the position the
 * board signed off is always recoverable.
 */
class AppetiteService
{
    public function __construct(private EscalationService $escalationService) {}

    public function create(array $data, User $author): RiskAppetite
    {
        return RiskAppetite::create([
            ...$data,
            'version_no' => 1,
            'status' => 'Draft',
            'created_by' => $author->id,
        ]);
    }

    public function submitForApproval(RiskAppetite $appetite): RiskAppetite
    {
        $this->transition($appetite, 'Pending Approval');

        return $appetite;
    }

    /** Maker-checker (R2): the author never approves their own statement. */
    public function approve(RiskAppetite $appetite, User $approver): RiskAppetite
    {
        if ($appetite->created_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'An appetite statement cannot be approved by its author.',
            ]);
        }

        if (! $approver->hasAnyRole(['Control Function Head', 'Executive Viewer'])) {
            throw ValidationException::withMessages([
                'approval' => 'Appetite statements are approved by the control function or the board.',
            ]);
        }

        $this->transition($appetite, 'Active', [
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'effective_from' => $appetite->effective_from ?? now()->toDateString(),
        ]);

        $appetite->auditAction('approved', null, ['by' => $approver->id]);

        // Only one Active statement per (entity, category) — approving this
        // one retires whatever it replaces.
        RiskAppetite::withoutGlobalScopes()
            ->where('tenant_id', $appetite->tenant_id)
            ->where('id', '!=', $appetite->id)
            ->where('status', 'Active')
            ->where('risk_category_id', $appetite->risk_category_id)
            ->where('entity_id', $appetite->entity_id)
            ->each(fn (RiskAppetite $old) => $this->markSuperseded($old, $appetite));

        return $appetite;
    }

    /**
     * Revising an approved statement creates the next version rather than
     * editing the approved one.
     */
    public function supersede(RiskAppetite $appetite, array $data, User $author): RiskAppetite
    {
        if ($appetite->status !== 'Active') {
            throw ValidationException::withMessages([
                'status' => 'Only an active appetite statement can be superseded.',
            ]);
        }

        return RiskAppetite::create([
            'tenant_id' => $appetite->tenant_id,
            'entity_id' => $data['entity_id'] ?? $appetite->entity_id,
            'risk_category_id' => $data['risk_category_id'] ?? $appetite->risk_category_id,
            'statement' => $data['statement'] ?? $appetite->statement,
            'appetite_level' => $data['appetite_level'] ?? $appetite->appetite_level,
            'tolerance_upper' => $data['tolerance_upper'] ?? $appetite->tolerance_upper,
            'tolerance_lower' => $data['tolerance_lower'] ?? $appetite->tolerance_lower,
            'capacity' => $data['capacity'] ?? $appetite->capacity,
            'metric_definition' => $data['metric_definition'] ?? $appetite->metric_definition,
            'review_due_at' => $data['review_due_at'] ?? $appetite->review_due_at,
            'effective_from' => $data['effective_from'] ?? null,
            'version_no' => $appetite->version_no + 1,
            'supersedes_id' => $appetite->id,
            'status' => 'Draft',
            'created_by' => $author->id,
        ]);
    }

    private function markSuperseded(RiskAppetite $old, RiskAppetite $replacement): void
    {
        $old->update([
            'status' => 'Superseded',
            'effective_to' => $replacement->effective_from ?? now()->toDateString(),
        ]);

        $old->auditAction('superseded', null, ['by_appetite' => $replacement->id]);
    }

    // ── Applicability & breach ───────────────────────────────────────

    /**
     * Most specific active statement wins, walking up the taxonomy: the
     * risk's own statement, then its category + entity, then that category,
     * then each ancestor category in turn, then entity-only, then the
     * tenant-wide statement. A risk filed under "Fraud" is therefore
     * governed by the "Operational" appetite when Fraud has none of its own.
     */
    public function applicableFor(Risk $risk): ?RiskAppetite
    {
        if ($risk->appetite_id) {
            $explicit = RiskAppetite::withoutGlobalScopes()->find($risk->appetite_id);

            if ($explicit?->status === 'Active') {
                return $explicit;
            }
        }

        $candidates = RiskAppetite::withoutGlobalScopes()
            ->where('tenant_id', $risk->tenant_id)
            ->active()
            ->get();

        foreach ($this->categoryChain($risk->risk_category_id) as $categoryId) {
            $found = $candidates->first(fn (RiskAppetite $a) => $a->risk_category_id === $categoryId
                    && $a->entity_id === $risk->entity_id)
                ?? $candidates->first(fn (RiskAppetite $a) => $a->risk_category_id === $categoryId
                    && $a->entity_id === null);

            if ($found) {
                return $found;
            }
        }

        return $candidates->first(fn (RiskAppetite $a) => $a->risk_category_id === null
                && $a->entity_id === $risk->entity_id && $a->entity_id !== null)
            ?? $candidates->first(fn (RiskAppetite $a) => $a->risk_category_id === null && $a->entity_id === null);
    }

    /**
     * A category and its ancestors, nearest first.
     *
     * @return array<int, int>
     */
    public function categoryChain(?int $categoryId): array
    {
        if (! $categoryId) {
            return [];
        }

        $categories = $this->categoryParents();
        $chain = [];

        while ($categoryId !== null && ! in_array($categoryId, $chain, true)) {
            $chain[] = $categoryId;
            $categoryId = $categories[$categoryId] ?? null;
        }

        return $chain;
    }

    /** @return array<int, int|null> category id => parent id, loaded once. */
    private function categoryParents(): array
    {
        return $this->categoryParents ??= RiskCategory::withoutGlobalScopes()
            ->pluck('parent_id', 'id')
            ->map(fn ($parentId) => $parentId !== null ? (int) $parentId : null)
            ->all();
    }

    /** @var array<int, int|null>|null */
    private ?array $categoryParents = null;

    /**
     * Re-evaluate a risk against its appetite. A newly breached risk is
     * flagged and escalated once; a risk that comes back inside tolerance
     * clears the flag so the board view never shows a stale breach.
     */
    public function evaluate(Risk $risk): bool
    {
        $appetite = $this->applicableFor($risk);

        if (! $appetite) {
            if ($risk->appetite_breached) {
                $risk->updateQuietly(['appetite_breached' => false, 'appetite_breach_at' => null]);
            }

            return false;
        }

        $breached = $appetite->isBreachedBy($risk->currentScore());
        $wasBreached = (bool) $risk->appetite_breached;

        if ($breached === $wasBreached) {
            return $breached;
        }

        $risk->updateQuietly([
            'appetite_breached' => $breached,
            'appetite_breach_at' => $breached ? now() : null,
        ]);

        if ($breached) {
            $risk->auditAction('appetite_breached', null, [
                'appetite_id' => $appetite->id,
                'score' => $risk->currentScore(),
                'tolerance_upper' => $appetite->tolerance_upper,
            ]);

            $this->escalationService->escalateAppetiteBreach($risk->refresh(), $appetite);
        } else {
            $risk->auditAction('appetite_restored', null, ['appetite_id' => $appetite->id]);
        }

        return $breached;
    }

    /** Re-evaluate every active risk — used by the daily command. */
    public function evaluateAll(?int $tenantId = null): int
    {
        $count = 0;

        Risk::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'active')
            ->chunkById(200, function (Collection $risks) use (&$count) {
                foreach ($risks as $risk) {
                    $this->evaluate($risk) && $count++;
                }
            });

        return $count;
    }

    // ── Board view ───────────────────────────────────────────────────

    /**
     * Category × appetite × current position, for the board dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function positions(int $tenantId): array
    {
        $appetites = RiskAppetite::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->with(['category', 'entity'])
            ->get();

        $risks = Risk::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get(['id', 'risk_category_id', 'entity_id', 'residual_rating', 'inherent_rating', 'appetite_breached']);

        return $appetites->map(function (RiskAppetite $appetite) use ($risks) {
            $scoped = $risks->filter(fn (Risk $risk) => (
                $appetite->risk_category_id === null
                || in_array($appetite->risk_category_id, $this->categoryChain($risk->risk_category_id), true)
            ) && (
                $appetite->entity_id === null || $risk->entity_id === $appetite->entity_id
            ));

            $current = $scoped->max(fn (Risk $risk) => $risk->currentScore());

            return [
                'id' => $appetite->id,
                'category' => $appetite->category?->name ?? 'All categories',
                'entity' => $appetite->entity?->name ?? 'Group-wide',
                'statement' => $appetite->statement,
                'appetite_level' => $appetite->appetite_level,
                'tolerance_upper' => $appetite->tolerance_upper,
                'tolerance_lower' => $appetite->tolerance_lower,
                'capacity' => $appetite->capacity,
                'current_position' => $current !== null ? round((float) $current, 2) : null,
                'status' => $appetite->positionFor($current !== null ? (float) $current : null),
                'risk_count' => $scoped->count(),
                'breach_count' => $scoped->where('appetite_breached', true)->count(),
                'review_due_at' => $appetite->review_due_at?->toDateString(),
            ];
        })->values()->all();
    }

    /** Categories with no active appetite statement — a governance gap. */
    public function categoriesWithoutAppetite(int $tenantId): array
    {
        $covered = RiskAppetite::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->pluck('risk_category_id')
            ->filter()
            ->all();

        return RiskCategory::query()
            ->whereNotIn('id', $covered)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name'])
            ->all();
    }

    private function transition(RiskAppetite $appetite, string $to, array $extra = []): void
    {
        $allowed = [
            'Draft' => ['Pending Approval'],
            'Pending Approval' => ['Active', 'Draft'],
            'Active' => ['Superseded'],
            'Superseded' => [],
        ][$appetite->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An appetite statement cannot move from {$appetite->status} to {$to}.",
            ]);
        }

        $appetite->update(['status' => $to, ...$extra]);
    }
}
