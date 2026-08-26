<?php

namespace App\Services;

use App\Models\EntityLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        // Phase 17 — strategic objectives and third parties join the graph
        // rather than getting their own join tables, which is what makes
        // "objective ← risk ← control ← KRI" one traversal (17.1).
        'objective' => ['ref' => 'code', 'title' => 'title', 'route' => 'objectives.show'],
        'vendor' => ['ref' => 'reference', 'title' => 'legal_name', 'route' => 'vendors.show'],
        // CR-04. Resolves through the tenant scope like everything else;
        // an investigation the viewer may not open renders as
        // "(removed record)" with no route, which is what makes it safe
        // to link a confidential matter into a graph other people see.
        'investigation' => ['ref' => 'reference', 'title' => 'title', 'route' => 'investigations.show'],
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

        $link = EntityLink::firstOrCreate(
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

        // CR3: a control mapped to a risk (or any edge in the linkage
        // graph) is a workflow event — mirror of the 'unlinked' record.
        if ($link->wasRecentlyCreated) {
            $link->auditAction('linked', null, [
                'source' => "{$sourceType}#{$sourceId}",
                'target' => "{$targetType}#{$targetId}",
                'relationship' => $relationship,
            ]);
        }

        return $link;
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

    /**
     * Neighbour ids of one target type for many source records at once —
     * the batch form of neighbours(), for pages that ask the same question
     * of every row (17.1's strategy map asks it of every objective).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, int>> source id → neighbour ids
     */
    public function neighbourIds(string $type, array $ids, string $targetType): array
    {
        $this->assertKnown($type);
        $this->assertKnown($targetType);

        if ($ids === []) {
            return [];
        }

        $links = EntityLink::query()
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('source_type', $type)->whereIn('source_id', $ids)
                    ->where('target_type', $targetType))
                ->orWhere(fn ($t) => $t->where('target_type', $type)->whereIn('target_id', $ids)
                    ->where('source_type', $targetType)))
            ->get(['source_type', 'source_id', 'target_type', 'target_id']);

        $result = [];

        foreach ($links as $link) {
            $isSource = $link->source_type === $type && in_array($link->source_id, $ids, true);
            $ownId = $isSource ? $link->source_id : $link->target_id;
            $otherId = $isSource ? $link->target_id : $link->source_id;

            $result[$ownId][] = $otherId;
        }

        return array_map(fn (array $neighbours) => array_values(array_unique($neighbours)), $result);
    }

    /**
     * How many of each risk's mapped controls are rated Weak — "are the
     * controls over this risk effective", answered for a whole page of
     * risks in one query (R6).
     *
     * @param  array<int, int>  $riskIds
     * @return array<int, int> risk id → ineffective control count
     */
    public function ineffectiveControlCounts(array $riskIds): array
    {
        if ($riskIds === []) {
            return [];
        }

        return DB::table('risk_control_map')
            ->join('controls', 'controls.id', '=', 'risk_control_map.control_id')
            ->whereIn('risk_control_map.risk_id', $riskIds)
            ->whereNull('controls.deleted_at')
            ->where('controls.overall_rating', 'Weak')
            ->groupBy('risk_control_map.risk_id')
            ->selectRaw('risk_control_map.risk_id, count(*) as total')
            ->pluck('total', 'risk_id')
            ->map(fn ($total) => (int) $total)
            ->all();
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
