<?php

namespace App\Services;

use App\Models\ControlRequirementMap;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\OrganisationUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Frameworks and their requirement trees. A framework is data (R1): it is
 * imported from and exported to the content-pack JSON format, versioned and
 * effective-dated, and a tenant may hold its own copy alongside the shipped
 * one without either overwriting the other.
 */
class FrameworkService
{
    /** Ratings that count a control as currently effective for coverage. */
    private const EFFECTIVE_RATINGS = ['Effective'];

    // ── Import / export ──────────────────────────────────────────────────

    /**
     * Export a framework in content-pack shape (Part D §D.1). Used by the
     * installer's diff report and by tenants exporting a customised copy.
     */
    public function export(Framework $framework): array
    {
        $framework->loadMissing('requirements');

        $byId = $framework->requirements->keyBy('id');

        return [
            'code' => $framework->code,
            'name' => $framework->name,
            'version' => $framework->version,
            'jurisdiction' => $framework->jurisdiction,
            'issuing_body' => $framework->issuing_body,
            'effective_from' => $framework->effective_from?->toDateString(),
            'source_url' => $framework->source_url,
            'verification_status' => $framework->verification_status,
            'framework' => [
                'code' => $framework->code,
                'name' => $framework->name,
                'issuing_body' => $framework->issuing_body,
                'jurisdiction' => $framework->jurisdiction,
                'version' => $framework->version,
                'category' => $framework->category,
                'effective_from' => $framework->effective_from?->toDateString(),
                'effective_to' => $framework->effective_to?->toDateString(),
                'is_certifiable' => $framework->is_certifiable,
                'source_url' => $framework->source_url,
                'verification_status' => $framework->verification_status,
                'description' => $framework->description,
            ],
            'requirements' => $framework->requirements
                ->sortBy([['level', 'asc'], ['sort_order', 'asc']])
                ->map(fn (FrameworkRequirement $r) => [
                    'ref_code' => $r->ref_code,
                    'parent_ref' => $r->parent_id ? $byId[$r->parent_id]?->ref_code : null,
                    'title' => $r->title,
                    'description' => $r->description,
                    'guidance' => $r->guidance,
                    'level' => $r->level,
                    'sort_order' => $r->sort_order,
                    'requirement_type' => $r->requirement_type,
                    'is_testable' => $r->is_testable,
                    'suggested_frequency' => $r->suggested_frequency,
                    'suggested_evidence' => $r->suggested_evidence,
                    'verification_status' => $r->verification_status,
                    'source_reference' => $r->source_reference,
                ])->values()->all(),
        ];
    }

    /**
     * Import a framework definition and its requirement tree. Idempotent on
     * (tenant, code, version): re-importing updates shipped rows in place and
     * never touches a tenant's own copy of the same framework.
     *
     * @param  array{framework: array, requirements?: array}  $pack
     */
    public function import(array $pack, ?int $tenantId = null, ?User $user = null): Framework
    {
        $definition = $pack['framework'] ?? [];

        if (empty($definition['code'])) {
            throw ValidationException::withMessages(['framework' => 'A framework pack must carry a framework code.']);
        }

        return DB::transaction(function () use ($pack, $definition, $tenantId, $user) {
            $framework = Framework::withoutGlobalScopes()->firstOrNew([
                'tenant_id' => $tenantId,
                'code' => $definition['code'],
                'version' => $definition['version'] ?? '1.0.0',
            ]);

            $framework->fill([
                'name' => $definition['name'] ?? $definition['code'],
                'issuing_body' => $definition['issuing_body'] ?? null,
                'jurisdiction' => $definition['jurisdiction'] ?? 'INTL',
                'category' => $definition['category'] ?? 'internal_control',
                'effective_from' => $definition['effective_from'] ?? null,
                'effective_to' => $definition['effective_to'] ?? null,
                'is_certifiable' => (bool) ($definition['is_certifiable'] ?? false),
                'source_url' => $definition['source_url'] ?? null,
                'verification_status' => $definition['verification_status'] ?? 'unverified',
                // D.7 step 3: a record that claims 'verified' must say who
                // confirmed it against the primary document, and when. The
                // pack-level attribution applies unless the framework block
                // carries its own.
                'verified_by' => $definition['verified_by'] ?? ($pack['verified_by'] ?? null) ?: null,
                'verified_at' => $definition['verified_at'] ?? $pack['verified_at'] ?? null,
                'description' => $definition['description'] ?? null,
                'is_active' => $definition['is_active'] ?? true,
            ])->save();

            $this->importRequirements($framework, $pack['requirements'] ?? []);

            $framework->auditAction('imported', null, [
                'version' => $framework->version,
                'requirements' => count($pack['requirements'] ?? []),
                'by' => $user?->id,
            ]);

            return $framework->refresh();
        });
    }

    /**
     * Two passes so a child may appear before its parent in the pack file.
     *
     * @param  array<int, array>  $rows
     */
    private function importRequirements(Framework $framework, array $rows): void
    {
        $ids = [];

        foreach ($rows as $index => $row) {
            if (empty($row['ref_code'])) {
                continue;
            }

            $requirement = FrameworkRequirement::firstOrNew([
                'framework_id' => $framework->id,
                'ref_code' => $row['ref_code'],
            ]);

            $requirement->fill([
                'title' => $row['title'] ?? $row['ref_code'],
                'description' => $row['description'] ?? null,
                'guidance' => $row['guidance'] ?? null,
                'level' => (int) ($row['level'] ?? 1),
                'sort_order' => (int) ($row['sort_order'] ?? $index),
                'requirement_type' => $row['requirement_type'] ?? 'principle',
                'is_testable' => (bool) ($row['is_testable'] ?? true),
                'suggested_frequency' => $row['suggested_frequency'] ?? null,
                'suggested_evidence' => $row['suggested_evidence'] ?? null,
                'verification_status' => $row['verification_status'] ?? $framework->verification_status,
                'source_reference' => $row['source_reference'] ?? null,
            ])->save();

            $ids[$row['ref_code']] = $requirement->id;
        }

        foreach ($rows as $row) {
            if (empty($row['ref_code']) || empty($row['parent_ref'])) {
                continue;
            }

            FrameworkRequirement::where('framework_id', $framework->id)
                ->where('ref_code', $row['ref_code'])
                ->update(['parent_id' => $ids[$row['parent_ref']] ?? null]);
        }
    }

    // ── Requirement tree ─────────────────────────────────────────────────

    public function addRequirement(Framework $framework, array $data): FrameworkRequirement
    {
        $parent = isset($data['parent_id'])
            ? FrameworkRequirement::where('framework_id', $framework->id)->find($data['parent_id'])
            : null;

        return $framework->requirements()->create([
            ...$data,
            'parent_id' => $parent?->id,
            'level' => $parent ? $parent->level + 1 : 1,
            'sort_order' => $data['sort_order'] ?? ($framework->requirements()
                ->where('parent_id', $parent?->id)->max('sort_order') + 1),
        ]);
    }

    public function updateRequirement(FrameworkRequirement $requirement, array $data): FrameworkRequirement
    {
        $requirement->update($data);

        return $requirement;
    }

    /**
     * Persist a drag-reorder. Levels are recomputed from the new parent so a
     * node dragged under a different branch keeps a consistent depth.
     *
     * @param  array<int, array{id: int, parent_id: ?int, sort_order: int}>  $ordering
     */
    public function reorderRequirements(Framework $framework, array $ordering): void
    {
        DB::transaction(function () use ($framework, $ordering) {
            $requirements = $framework->requirements()->get()->keyBy('id');

            foreach ($ordering as $row) {
                $requirement = $requirements->get($row['id'] ?? 0);

                if (! $requirement) {
                    continue;
                }

                $parent = $requirements->get($row['parent_id'] ?? 0);

                if ($parent && $this->isDescendant($requirements, $parent, $requirement->id)) {
                    throw ValidationException::withMessages([
                        'ordering' => 'A requirement cannot be moved beneath one of its own descendants.',
                    ]);
                }

                $requirement->update([
                    'parent_id' => $parent?->id,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'level' => $parent ? $parent->level + 1 : 1,
                ]);
            }
        });
    }

    private function isDescendant(Collection $requirements, FrameworkRequirement $candidate, int $ancestorId): bool
    {
        $cursor = $candidate;
        $guard = 0;

        while ($cursor && $guard++ < 50) {
            if ($cursor->id === $ancestorId) {
                return true;
            }

            $cursor = $cursor->parent_id ? $requirements->get($cursor->parent_id) : null;
        }

        return false;
    }

    /** Nested tree for the explorer UI — one query, no N+1. */
    public function tree(Framework $framework): array
    {
        $requirements = $framework->requirements()
            ->orderBy('level')->orderBy('sort_order')->orderBy('ref_code')
            ->get();

        $coverage = $this->requirementCoverage($framework);

        $nodes = $requirements->map(fn (FrameworkRequirement $r) => [
            'id' => $r->id,
            'parent_id' => $r->parent_id,
            'ref_code' => $r->ref_code,
            'title' => $r->title,
            'description' => $r->description,
            'requirement_type' => $r->requirement_type,
            'level' => $r->level,
            'sort_order' => $r->sort_order,
            'is_testable' => $r->is_testable,
            'verification_status' => $r->verification_status,
            'source_reference' => $r->source_reference,
            'coverage' => $coverage[$r->id] ?? ['mapped' => 0, 'effective' => 0, 'controls' => []],
            'children' => [],
        ])->keyBy('id')->all();

        $roots = [];

        foreach ($nodes as $id => $node) {
            if ($node['parent_id'] && isset($nodes[$node['parent_id']])) {
                continue;
            }

            $roots[] = $id;
        }

        $build = function (int $id) use (&$build, $nodes): array {
            $node = $nodes[$id];
            $node['children'] = array_values(array_map($build, array_keys(array_filter(
                $nodes, fn ($n) => $n['parent_id'] === $id
            ))));

            return $node;
        };

        return array_map($build, $roots);
    }

    // ── Coverage ─────────────────────────────────────────────────────────

    /**
     * Per-requirement coverage for a framework, keyed by requirement id.
     * Only approved mappings to non-retired controls count (business rule);
     * "effective" additionally requires a published Effective rating.
     *
     * @return array<int, array{mapped: int, effective: int, controls: array}>
     */
    public function requirementCoverage(Framework $framework, ?int $entityId = null): array
    {
        $rows = ControlRequirementMap::query()
            ->approved()
            ->whereIn('requirement_id', $framework->requirements()->select('id'))
            ->join('controls', 'controls.id', '=', 'control_requirement_map.control_id')
            ->whereNull('controls.deleted_at')
            ->where('controls.status', 'Active')
            ->when($entityId, fn ($q, $e) => $q->where('controls.unit_id', $e))
            ->get([
                'control_requirement_map.requirement_id',
                'control_requirement_map.coverage',
                'controls.id as control_id',
                'controls.control_ref',
                'controls.title as control_title',
                'controls.operating_effectiveness',
                'controls.unit_id',
            ]);

        $effectiveControlIds = $this->currentlyEffectiveControlIds($rows->pluck('control_id')->unique()->all());

        $coverage = [];

        foreach ($rows as $row) {
            $isEffective = in_array($row->control_id, $effectiveControlIds, true);

            $coverage[$row->requirement_id] ??= ['mapped' => 0, 'effective' => 0, 'controls' => []];
            $coverage[$row->requirement_id]['mapped']++;
            $coverage[$row->requirement_id]['effective'] += $isEffective ? 1 : 0;
            $coverage[$row->requirement_id]['controls'][] = [
                'id' => $row->control_id,
                'control_ref' => $row->control_ref,
                'title' => $row->control_title,
                'coverage' => $row->coverage,
                'operating_effectiveness' => $row->operating_effectiveness,
                'is_effective' => $isEffective,
            ];
        }

        return $coverage;
    }

    /**
     * Headline coverage: the share of testable requirements with at least one
     * approved mapping to an Active control, and the share where that control
     * also carries a current effective rating.
     */
    public function coverage(Framework $framework, ?int $entityId = null): array
    {
        $testableIds = $framework->requirements()->testable()->pluck('id');
        $perRequirement = $this->requirementCoverage($framework, $entityId);

        $mapped = 0;
        $effective = 0;

        foreach ($testableIds as $id) {
            $row = $perRequirement[$id] ?? null;

            if (! $row) {
                continue;
            }

            $mapped += $row['mapped'] > 0 ? 1 : 0;
            $effective += $row['effective'] > 0 ? 1 : 0;
        }

        $total = $testableIds->count();

        return [
            'framework_id' => $framework->id,
            'code' => $framework->code,
            'name' => $framework->name,
            'testable_requirements' => $total,
            'mapped_requirements' => $mapped,
            'effective_requirements' => $effective,
            'unmapped_requirements' => $total - $mapped,
            'mapped_percentage' => $total > 0 ? (int) round($mapped / $total * 100) : 0,
            'effective_percentage' => $total > 0 ? (int) round($effective / $total * 100) : 0,
            'verification_status' => $framework->verification_status,
        ];
    }

    /**
     * Framework requirements × entity heatmap — the screen executives
     * screenshot. One coverage pass per entity, no per-cell query.
     *
     * @param  Collection<int, OrganisationUnit>  $entities
     */
    public function coverageMatrix(Framework $framework, Collection $entities): array
    {
        $requirements = $framework->requirements()->testable()
            ->orderBy('level')->orderBy('sort_order')
            ->get(['id', 'ref_code', 'title', 'level', 'verification_status']);

        $cells = [];

        foreach ($entities as $entity) {
            $coverage = $this->requirementCoverage($framework, $entity->id);

            foreach ($requirements as $requirement) {
                $row = $coverage[$requirement->id] ?? null;

                $cells[$requirement->id][$entity->id] = match (true) {
                    $row === null => 'unmapped',
                    $row['effective'] > 0 => 'effective',
                    default => 'mapped',
                };
            }
        }

        return [
            'requirements' => $requirements->map(fn ($r) => [
                'id' => $r->id,
                'ref_code' => $r->ref_code,
                'title' => $r->title,
                'level' => $r->level,
                'verification_status' => $r->verification_status,
            ])->all(),
            'entities' => $entities->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()->all(),
            'cells' => $cells,
        ];
    }

    /**
     * Controls whose latest published rating is currently effective.
     *
     * @param  array<int, int>  $controlIds
     * @return array<int, int>
     */
    private function currentlyEffectiveControlIds(array $controlIds): array
    {
        if ($controlIds === []) {
            return [];
        }

        return DB::table('effectiveness_ratings as er')
            ->whereIn('er.control_id', $controlIds)
            ->where('er.status', 'Published')
            ->whereNull('er.deleted_at')
            ->whereIn('er.overall_rating', self::EFFECTIVE_RATINGS)
            ->whereRaw('er.approved_at = (
                select max(er2.approved_at) from effectiveness_ratings er2
                where er2.control_id = er.control_id and er2.status = ? and er2.deleted_at is null
            )', ['Published'])
            ->pluck('er.control_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
