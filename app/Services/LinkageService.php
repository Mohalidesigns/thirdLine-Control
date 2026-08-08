<?php

namespace App\Services;

use App\Models\EntityLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The linkage graph (10.6). Every relationship in the product that is not
 * a hard foreign key lives here, addressed by alias, so risk ↔ control ↔
 * KRI ↔ exception ↔ obligation ↔ policy is one navigable graph rather
 * than a pile of join tables.
 *
 * Adjacency is computed server-side and capped: the browser receives a
 * finished node/edge list, never the whole register (R6).
 */
class LinkageService
{
    /** Display columns and detail route per node alias. */
    private const DISPLAY = [
        'risk' => ['ref' => 'code', 'title' => 'title', 'route' => 'risks.show'],
        'control' => ['ref' => 'control_ref', 'title' => 'title', 'route' => 'controls.show'],
        'metric' => ['ref' => 'code', 'title' => 'name', 'route' => 'metrics.show'],
        'exception' => ['ref' => 'reference', 'title' => 'title', 'route' => 'exceptions.show'],
        'treatment' => ['ref' => 'reference', 'title' => 'title', 'route' => 'treatments.show'],
        'document' => ['ref' => 'reference', 'title' => 'title', 'route' => 'documents.show'],
        'obligation' => ['ref' => 'obligation_ref', 'title' => 'title', 'route' => 'obligations.show'],
        'requirement' => ['ref' => 'ref_code', 'title' => 'title', 'route' => null],
        'improvement' => ['ref' => 'reference', 'title' => 'title', 'route' => null],
        'process' => ['ref' => 'code', 'title' => 'name', 'route' => null],
        'policy' => ['ref' => 'policy_ref', 'title' => 'title', 'route' => 'policies.show'],
        'incident' => ['ref' => 'incident_ref', 'title' => 'title', 'route' => 'incidents.show'],
        'complaint' => ['ref' => 'complaint_ref', 'title' => 'subject', 'route' => 'complaints.show'],
        // Cases resolve through the allowlist scope like everything else: a
        // node the viewer may not see renders as "(removed record)" with no
        // route, which is the right answer for a confidential case.
        'case' => ['ref' => 'case_ref', 'title' => 'title', 'route' => 'cases.show'],
    ];

    /** Hard cap on the nodes any single graph response may carry. */
    public const MAX_NODES = 80;

    public function link(
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
        string $relationship = 'relates_to',
        ?int $strength = null,
        ?string $notes = null,
    ): EntityLink {
        $this->assertKnown($sourceType);
        $this->assertKnown($targetType);

        if ($sourceType === $targetType && $sourceId === $targetId) {
            throw ValidationException::withMessages([
                'target_id' => 'A record cannot be linked to itself.',
            ]);
        }

        if (! in_array($relationship, EntityLink::RELATIONSHIPS, true)) {
            throw ValidationException::withMessages([
                'relationship' => "'{$relationship}' is not a known relationship.",
            ]);
        }

        return EntityLink::firstOrCreate(
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'relationship' => $relationship,
            ],
            [
                'tenant_id' => $this->resolveTenantId($sourceType, $sourceId, $targetType, $targetId),
                'strength' => $strength ?? 3,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ],
        );
    }

    /**
     * Edges are created from scheduled commands and seeders as well as from
     * requests, so the tenant is taken from the records being linked when
     * there is no authenticated user to take it from.
     */
    private function resolveTenantId(string $sourceType, int $sourceId, string $targetType, int $targetId): int
    {
        if ($tenantId = auth()->user()?->tenant_id) {
            return $tenantId;
        }

        foreach ([[$sourceType, $sourceId], [$targetType, $targetId]] as [$type, $id]) {
            $modelClass = EntityLink::modelClassFor($type);

            if (! $modelClass) {
                continue;
            }

            $tenantId = $modelClass::withoutGlobalScopes()->whereKey($id)->value('tenant_id');

            if ($tenantId) {
                return (int) $tenantId;
            }
        }

        throw ValidationException::withMessages([
            'tenant' => 'Neither end of this link belongs to a tenant.',
        ]);
    }

    public function unlink(EntityLink $link): void
    {
        $link->auditAction('unlinked', $link->getAttributes(), null);
        $link->delete();
    }

    /**
     * Everything one hop from a record — what the Relationships panel on a
     * detail page renders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function neighbours(string $type, int $id, int $limit = 50): array
    {
        $this->assertKnown($type);

        $links = EntityLink::query()
            ->touching($type, $id)
            ->orderByDesc('strength')
            ->limit($limit)
            ->get();

        $resolved = $this->resolveMany($this->endpointsOf($links, $type, $id));

        return $links->map(function (EntityLink $link) use ($type, $id, $resolved) {
            $isSource = $link->source_type === $type && $link->source_id === $id;
            $otherType = $isSource ? $link->target_type : $link->source_type;
            $otherId = $isSource ? $link->target_id : $link->source_id;
            $node = $resolved["{$otherType}:{$otherId}"] ?? null;

            return [
                'link_id' => $link->id,
                'direction' => $isSource ? 'outgoing' : 'incoming',
                'relationship' => $link->relationship,
                'strength' => $link->strength,
                'notes' => $link->notes,
                'type' => $otherType,
                'type_label' => EntityLink::NODE_LABELS[$otherType] ?? $otherType,
                'id' => $otherId,
                'ref' => $node['ref'] ?? null,
                'title' => $node['title'] ?? '(removed record)',
                'route' => $node['route'] ?? null,
                'missing' => $node === null,
            ];
        })->all();
    }

    /**
     * Breadth-first to `$hops` (default two), capped at MAX_NODES so a
     * densely linked risk cannot produce a graph the browser chokes on.
     * When the cap bites, `truncated` says so — a silent cut would read as
     * "this is everything".
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, truncated: bool, hops: int}
     */
    public function graph(string $type, int $id, int $hops = 2, int $maxNodes = self::MAX_NODES): array
    {
        $this->assertKnown($type);

        $rootKey = "{$type}:{$id}";
        $visited = [$rootKey => 0];
        $frontier = [[$type, $id]];
        $edges = [];
        $truncated = false;

        for ($depth = 1; $depth <= max(1, $hops); $depth++) {
            if ($frontier === []) {
                break;
            }

            $next = [];

            foreach ($frontier as [$frontierType, $frontierId]) {
                $links = EntityLink::query()->touching($frontierType, $frontierId)->get();

                foreach ($links as $link) {
                    $edgeKey = "{$link->source_type}:{$link->source_id}->{$link->target_type}:{$link->target_id}:{$link->relationship}";

                    $isSource = $link->source_type === $frontierType && $link->source_id === $frontierId;
                    $otherType = $isSource ? $link->target_type : $link->source_type;
                    $otherId = $isSource ? $link->target_id : $link->source_id;
                    $otherKey = "{$otherType}:{$otherId}";

                    if (! isset($visited[$otherKey])) {
                        if (count($visited) >= $maxNodes) {
                            $truncated = true;

                            continue;
                        }

                        $visited[$otherKey] = $depth;
                        $next[] = [$otherType, $otherId];
                    }

                    $edges[$edgeKey] = [
                        'source' => "{$link->source_type}:{$link->source_id}",
                        'target' => "{$link->target_type}:{$link->target_id}",
                        'relationship' => $link->relationship,
                        'strength' => $link->strength,
                    ];
                }
            }

            $frontier = $next;
        }

        $resolved = $this->resolveMany(array_keys($visited));

        $nodes = [];

        foreach ($visited as $key => $depth) {
            [$nodeType, $nodeId] = explode(':', $key);
            $node = $resolved[$key] ?? null;

            $nodes[] = [
                'key' => $key,
                'type' => $nodeType,
                'type_label' => EntityLink::NODE_LABELS[$nodeType] ?? $nodeType,
                'id' => (int) $nodeId,
                'depth' => $depth,
                'is_root' => $key === $rootKey,
                'ref' => $node['ref'] ?? null,
                'title' => $node['title'] ?? '(removed record)',
                'route' => $node['route'] ?? null,
            ];
        }

        // Drop edges whose far end was cut by the node cap.
        $keys = array_column($nodes, 'key');
        $edges = array_values(array_filter(
            $edges,
            fn (array $edge) => in_array($edge['source'], $keys, true) && in_array($edge['target'], $keys, true),
        ));

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'truncated' => $truncated,
            'hops' => max(1, $hops),
        ];
    }

    /** Candidate records of a type the user can link to, for the picker. */
    public function candidates(string $type, ?string $search = null, int $limit = 25): array
    {
        $this->assertKnown($type);

        $modelClass = EntityLink::modelClassFor($type);
        $display = self::DISPLAY[$type];

        return $modelClass::query()
            ->when($search, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where($display['title'], 'like', "%{$term}%")
                ->orWhere($display['ref'], 'like', "%{$term}%")))
            ->orderBy($display['ref'])
            ->limit($limit)
            ->get(['id', $display['ref'], $display['title']])
            ->map(fn (Model $record) => [
                'id' => $record->id,
                'ref' => $record->{$display['ref']},
                'title' => $record->{$display['title']},
            ])
            ->all();
    }

    /** @return array<int, string> "type:id" keys at the far end of each link */
    private function endpointsOf(Collection $links, string $type, int $id): array
    {
        return $links->map(function (EntityLink $link) use ($type, $id) {
            $isSource = $link->source_type === $type && $link->source_id === $id;

            return $isSource
                ? "{$link->target_type}:{$link->target_id}"
                : "{$link->source_type}:{$link->source_id}";
        })->unique()->values()->all();
    }

    /**
     * Resolve node labels in one query per type — the graph must never run
     * a query per node (R6, no N+1).
     *
     * @param  array<int, string>  $keys
     * @return array<string, array<string, mixed>>
     */
    private function resolveMany(array $keys): array
    {
        $byType = [];

        foreach ($keys as $key) {
            [$type, $id] = explode(':', $key);
            $byType[$type][] = (int) $id;
        }

        $resolved = [];

        foreach ($byType as $type => $ids) {
            $modelClass = EntityLink::modelClassFor($type);
            $display = self::DISPLAY[$type] ?? null;

            if (! $modelClass || ! $display) {
                continue;
            }

            $modelClass::query()
                ->whereIn('id', array_unique($ids))
                ->get(['id', $display['ref'], $display['title']])
                ->each(function (Model $record) use (&$resolved, $type, $display) {
                    $resolved["{$type}:{$record->id}"] = [
                        'ref' => $record->{$display['ref']},
                        'title' => $record->{$display['title']},
                        'route' => $display['route'],
                    ];
                });
        }

        return $resolved;
    }

    private function assertKnown(string $type): void
    {
        if (! array_key_exists($type, EntityLink::NODE_TYPES)) {
            throw ValidationException::withMessages([
                'type' => "'{$type}' is not a linkable record type.",
            ]);
        }
    }
}
