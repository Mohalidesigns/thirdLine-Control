<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlRequirementMap;
use App\Models\Framework;
use App\Models\FrameworkMapping;
use App\Models\FrameworkRequirement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The "test once, satisfy many" graph.
 *
 * Two kinds of edge live here: requirement ↔ requirement across frameworks
 * (framework_mappings) and control → requirement within a tenant
 * (control_requirement_map). The second is maker-checker: an officer maps,
 * a Control Function Head approves, and an unapproved mapping counts for
 * nothing (R2).
 *
 * Suggestions are deterministic — cosine similarity over TF-IDF vectors of
 * the requirement text. Phase 14 adds the AI-assisted variant; it will still
 * produce drafts a human approves (R4).
 */
class MappingService
{
    /** Tokens too common in control language to carry signal. */
    private const STOP_WORDS = [
        'the', 'and', 'of', 'to', 'a', 'in', 'for', 'is', 'are', 'be', 'that',
        'with', 'by', 'on', 'as', 'or', 'an', 'it', 'its', 'this', 'these',
        'shall', 'must', 'should', 'may', 'will', 'has', 'have', 'from', 'at',
        'any', 'all', 'such', 'which', 'their', 'there', 'been', 'other',
    ];

    private const MIN_SIMILARITY = 0.08;

    // ── Control → requirement (maker-checker) ────────────────────────────

    public function mapControl(Control $control, FrameworkRequirement $requirement, User $user, array $data = []): ControlRequirementMap
    {
        $existing = ControlRequirementMap::where('control_id', $control->id)
            ->where('requirement_id', $requirement->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'requirement_id' => "{$control->control_ref} is already mapped to {$requirement->ref_code}.",
            ]);
        }

        $map = ControlRequirementMap::create([
            // Stamped from the mapping user rather than left to the global
            // scope, so the service works outside an HTTP request too.
            'tenant_id' => $user->tenant_id ?? $control->tenant_id,
            'control_id' => $control->id,
            'requirement_id' => $requirement->id,
            'coverage' => $data['coverage'] ?? 'full',
            'notes' => $data['notes'] ?? null,
            'mapped_by' => $user->id,
            'mapped_at' => now(),
        ]);

        $map->auditAction('mapped', null, [
            'control_ref' => $control->control_ref,
            'requirement' => $requirement->ref_code,
            'coverage' => $map->coverage,
        ]);

        return $map;
    }

    /**
     * Maker-checker with no administrator bypass (R2): the officer who
     * created the mapping can never approve it.
     */
    public function approveMapping(ControlRequirementMap $map, User $approver): ControlRequirementMap
    {
        if ($map->mapped_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A control-to-requirement mapping cannot be approved by the user who created it.',
            ]);
        }

        if ($map->isApproved()) {
            throw ValidationException::withMessages(['approval' => 'This mapping is already approved.']);
        }

        $map->update(['approved_by' => $approver->id, 'approved_at' => now()]);
        $map->auditAction('mapping-approved');

        return $map;
    }

    public function rejectMapping(ControlRequirementMap $map, User $approver, string $reason): void
    {
        if ($map->mapped_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A control-to-requirement mapping cannot be rejected by the user who created it.',
            ]);
        }

        $map->auditAction('mapping-rejected', null, ['reason' => $reason]);
        $map->delete();
    }

    public function unmap(ControlRequirementMap $map): void
    {
        $map->auditAction('unmapped');
        $map->delete();
    }

    /**
     * Every requirement, in every framework, that one control satisfies —
     * directly, and indirectly through equivalent/partial framework mappings.
     * This is the "test once, satisfy many" proof.
     */
    public function crossFrameworkCoverage(Control $control): array
    {
        $direct = ControlRequirementMap::where('control_id', $control->id)
            ->with(['requirement.framework'])
            ->get();

        $directIds = $direct->pluck('requirement_id')->all();

        $indirect = $directIds === [] ? collect() : FrameworkMapping::query()
            ->whereIn('source_requirement_id', $directIds)
            ->whereIn('relationship', ['equivalent', 'partial'])
            ->with(['target.framework'])
            ->get();

        $rows = [];

        foreach ($direct as $map) {
            $requirement = $map->requirement;

            if (! $requirement?->framework) {
                continue;
            }

            $rows[] = [
                'framework' => $requirement->framework->code,
                'framework_name' => $requirement->framework->name,
                'framework_id' => $requirement->framework->id,
                'requirement_id' => $requirement->id,
                'ref_code' => $requirement->ref_code,
                'title' => $requirement->title,
                'link' => 'direct',
                'coverage' => $map->coverage,
                'approved' => $map->isApproved(),
                'verification_status' => $requirement->verification_status,
            ];
        }

        foreach ($indirect as $link) {
            $requirement = $link->target;

            if (! $requirement?->framework || in_array($requirement->id, $directIds, true)) {
                continue;
            }

            $rows[] = [
                'framework' => $requirement->framework->code,
                'framework_name' => $requirement->framework->name,
                'framework_id' => $requirement->framework->id,
                'requirement_id' => $requirement->id,
                'ref_code' => $requirement->ref_code,
                'title' => $requirement->title,
                'link' => $link->relationship,
                'coverage' => $link->relationship === 'equivalent' ? 'full' : 'partial',
                // An inherited claim is only as good as the mapping it rides on.
                'approved' => $direct->firstWhere('requirement_id', $link->source_requirement_id)?->isApproved() ?? false,
                'verification_status' => $requirement->verification_status,
            ];
        }

        usort($rows, fn ($a, $b) => [$a['framework'], $a['ref_code']] <=> [$b['framework'], $b['ref_code']]);

        return $rows;
    }

    // ── Requirement ↔ requirement ────────────────────────────────────────

    public function linkRequirements(
        FrameworkRequirement $source,
        FrameworkRequirement $target,
        string $relationship,
        User $user,
        array $data = [],
    ): FrameworkMapping {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target' => 'A requirement cannot be mapped to itself.']);
        }

        if (! in_array($relationship, FrameworkMapping::RELATIONSHIPS, true)) {
            throw ValidationException::withMessages(['relationship' => 'Unknown mapping relationship.']);
        }

        return FrameworkMapping::updateOrCreate(
            ['source_requirement_id' => $source->id, 'target_requirement_id' => $target->id],
            [
                'relationship' => $relationship,
                'coverage_percentage' => (int) ($data['coverage_percentage'] ?? 100),
                'rationale' => $data['rationale'] ?? null,
                'created_by' => $user->id,
            ],
        );
    }

    public function verifyLink(FrameworkMapping $mapping, User $verifier): FrameworkMapping
    {
        if ($mapping->created_by === $verifier->id) {
            throw ValidationException::withMessages([
                'verification' => 'A cross-framework mapping cannot be verified by the user who created it.',
            ]);
        }

        $mapping->update(['verified_by' => $verifier->id, 'verified_at' => now()]);
        $mapping->auditAction('mapping-verified');

        return $mapping;
    }

    // ── Deterministic suggestions ────────────────────────────────────────

    /**
     * Suggest requirements in other frameworks that look equivalent to the
     * given one, by cosine similarity over TF-IDF vectors. Deterministic and
     * explainable: the shared terms are returned with the score so a human
     * can judge the suggestion rather than trust it.
     *
     * @return array<int, array{requirement_id: int, score: float, terms: array}>
     */
    public function suggestRequirementMappings(FrameworkRequirement $requirement, ?Framework $target = null, int $limit = 10): array
    {
        $candidates = FrameworkRequirement::query()
            ->where('framework_id', '!=', $requirement->framework_id)
            ->when($target, fn ($q, $t) => $q->where('framework_id', $t->id))
            ->whereIn('framework_id', Framework::query()->active()->select('id'))
            ->get(['id', 'framework_id', 'ref_code', 'title', 'description']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $documents = [];
        $documents['source'] = $this->tokenise($requirement->title.' '.$requirement->description);

        foreach ($candidates as $candidate) {
            $documents[$candidate->id] = $this->tokenise($candidate->title.' '.$candidate->description);
        }

        $idf = $this->inverseDocumentFrequencies($documents);
        $sourceVector = $this->vector($documents['source'], $idf);

        $scored = [];

        foreach ($candidates as $candidate) {
            $vector = $this->vector($documents[$candidate->id], $idf);
            $score = $this->cosine($sourceVector, $vector);

            if ($score < self::MIN_SIMILARITY) {
                continue;
            }

            $shared = array_slice(array_keys(array_intersect_key($sourceVector, $vector)), 0, 6);

            $scored[] = [
                'requirement_id' => $candidate->id,
                'framework_id' => $candidate->framework_id,
                'ref_code' => $candidate->ref_code,
                'title' => $candidate->title,
                'score' => round($score, 4),
                'terms' => $shared,
                'suggested_relationship' => $score >= 0.6 ? 'equivalent' : ($score >= 0.3 ? 'partial' : 'supports'),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Suggest controls in the tenant's library that could satisfy a
     * requirement. Same maths, drafts only — a human still maps and a second
     * human still approves.
     */
    public function suggestControlsForRequirement(FrameworkRequirement $requirement, int $limit = 10): array
    {
        $mapped = ControlRequirementMap::where('requirement_id', $requirement->id)->pluck('control_id')->all();

        $controls = Control::query()
            ->where('is_template', false)
            ->whereNotIn('id', $mapped)
            ->whereIn('status', ['Active', 'Under Review'])
            ->get(['id', 'control_ref', 'title', 'description', 'objective']);

        if ($controls->isEmpty()) {
            return [];
        }

        $documents = ['source' => $this->tokenise($requirement->title.' '.$requirement->description.' '.$requirement->guidance)];

        foreach ($controls as $control) {
            $documents[$control->id] = $this->tokenise($control->title.' '.$control->description.' '.$control->objective);
        }

        $idf = $this->inverseDocumentFrequencies($documents);
        $sourceVector = $this->vector($documents['source'], $idf);

        $scored = [];

        foreach ($controls as $control) {
            $score = $this->cosine($sourceVector, $this->vector($documents[$control->id], $idf));

            if ($score < self::MIN_SIMILARITY) {
                continue;
            }

            $scored[] = [
                'control_id' => $control->id,
                'control_ref' => $control->control_ref,
                'title' => $control->title,
                'score' => round($score, 4),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /** Bulk-apply a set of suggested control mappings as unapproved drafts. */
    public function bulkMap(FrameworkRequirement $requirement, array $controlIds, User $user, string $coverage = 'supporting'): int
    {
        return DB::transaction(function () use ($requirement, $controlIds, $user, $coverage) {
            $created = 0;

            foreach (Control::whereIn('id', $controlIds)->get() as $control) {
                $exists = ControlRequirementMap::where('control_id', $control->id)
                    ->where('requirement_id', $requirement->id)->exists();

                if ($exists) {
                    continue;
                }

                $this->mapControl($control, $requirement, $user, ['coverage' => $coverage]);
                $created++;
            }

            return $created;
        });
    }

    // ── TF-IDF ───────────────────────────────────────────────────────────

    /**
     * @return array<string, int>
     */
    private function tokenise(?string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $counts = [];

        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string|int, array<string, int>>  $documents
     * @return array<string, float>
     */
    private function inverseDocumentFrequencies(array $documents): array
    {
        $total = max(1, count($documents));
        $appearances = [];

        foreach ($documents as $terms) {
            foreach (array_keys($terms) as $term) {
                $appearances[$term] = ($appearances[$term] ?? 0) + 1;
            }
        }

        $idf = [];

        foreach ($appearances as $term => $count) {
            $idf[$term] = log(($total + 1) / ($count + 1)) + 1;
        }

        return $idf;
    }

    /**
     * @param  array<string, int>  $terms
     * @param  array<string, float>  $idf
     * @return array<string, float>
     */
    private function vector(array $terms, array $idf): array
    {
        $vector = [];

        foreach ($terms as $term => $count) {
            $vector[$term] = (1 + log($count)) * ($idf[$term] ?? 1.0);
        }

        return $vector;
    }

    /**
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;

        foreach (array_intersect_key($a, $b) as $term => $weight) {
            $dot += $weight * $b[$term];
        }

        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v ** 2, $a)))
            * sqrt(array_sum(array_map(fn ($v) => $v ** 2, $b)));

        return $magnitude > 0 ? $dot / $magnitude : 0.0;
    }
}
