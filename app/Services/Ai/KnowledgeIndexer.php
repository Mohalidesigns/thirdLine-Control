<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeChunk;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Document;
use App\Models\FrameworkRequirement;
use App\Models\Incident;
use App\Models\Policy;
use App\Models\RegulatoryObligation;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the RAG index (Part C §C.7).
 *
 * Chunking splits on the record's own semantic boundaries — a control's
 * fields, a policy's sections, an obligation's description — rather than at
 * a fixed character offset, then packs those pieces up to the target size.
 * A control objective cut in half mid-sentence retrieves badly and cites
 * worse.
 *
 * Every chunk carries the permission_key of its source. That column is what
 * makes retrieval safe, so it is set here from configuration and nowhere
 * else: a source without a declared permission is not indexed at all rather
 * than defaulting to something permissive.
 *
 * Confidential documents are excluded outright. Phase 9 gates them on an
 * access-role list per document, which retrieval's single permission_key
 * cannot express — and a filter that cannot express the rule must not
 * approximate it.
 */
class KnowledgeIndexer
{
    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * The models that produce chunks, keyed by source_type. Adding a source
     * means a row here and a matching entry in config('ai.retrieval.sources')
     * carrying its permission — the two are checked against each other so a
     * half-registered source fails loudly at index time.
     *
     * @return array<string, class-string<Model>>
     */
    public static function sourceMap(): array
    {
        return [
            'control' => Control::class,
            'risk' => Risk::class,
            'policy' => Policy::class,
            'obligation' => RegulatoryObligation::class,
            'framework_requirement' => FrameworkRequirement::class,
            'incident' => Incident::class,
            'exception' => ControlException::class,
            'document' => Document::class,
        ];
    }

    public static function sourceTypeFor(Model $model): ?string
    {
        foreach (self::sourceMap() as $type => $class) {
            if ($model instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Rebuild one record's chunks. Idempotent: unchanged content keeps its
     * row and its indexed_at, so a mass re-index does not churn the table.
     */
    public function index(Model $record): int
    {
        $type = self::sourceTypeFor($record);

        if (! $type) {
            return 0;
        }

        $permission = (string) (config("ai.retrieval.sources.{$type}.permission") ?? '');

        if ($permission === '') {
            // Unreachable through the shipped configuration; a source with
            // no permission would be readable by anyone, so refuse rather
            // than index it under a guess.
            return 0;
        }

        $tenantId = $this->tenantIdFor($record);

        if (! $tenantId || $this->isExcluded($record)) {
            $this->purge($type, (int) $record->getKey());

            return 0;
        }

        $segments = $this->segments($record, $type);
        $chunks = $this->pack($segments);
        $title = $this->titleFor($record, $type);
        $metadata = $this->metadataFor($record, $type);

        foreach ($chunks as $index => $content) {
            $hash = hash('sha256', $content);

            $existing = AiKnowledgeChunk::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('source_type', $type)
                ->where('source_id', $record->getKey())
                ->where('chunk_index', $index)
                ->first();

            if ($existing && $existing->content_hash === $hash && ! $existing->is_stale) {
                continue;
            }

            AiKnowledgeChunk::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'source_type' => $type,
                    'source_id' => $record->getKey(),
                    'chunk_index' => $index,
                ],
                [
                    'title' => $title,
                    'content' => $content,
                    'content_hash' => $hash,
                    'embedding' => $this->embeddings->embed($title.' '.$content),
                    'metadata' => $metadata,
                    'permission_key' => $permission,
                    'token_count' => $this->estimateTokens($content),
                    'indexed_at' => now(),
                    'is_stale' => false,
                ],
            );
        }

        // A record that shrank leaves orphan chunks behind, which would keep
        // answering questions from text the record no longer contains.
        AiKnowledgeChunk::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $type)
            ->where('source_id', $record->getKey())
            ->where('chunk_index', '>=', count($chunks))
            ->delete();

        return count($chunks);
    }

    /**
     * Mark a record's chunks out of date without rebuilding them.
     *
     * Called synchronously on save so the write is never blocked; the queued
     * job does the real work. Until it runs the stale chunk is still
     * retrievable, because a slightly old control description is a better
     * answer than a missing one.
     */
    public function markStale(Model $record): void
    {
        $type = self::sourceTypeFor($record);

        if (! $type) {
            return;
        }

        AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', $type)
            ->where('source_id', $record->getKey())
            ->update(['is_stale' => true]);
    }

    public function purge(string $type, int $sourceId): void
    {
        AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', $type)
            ->where('source_id', $sourceId)
            ->delete();
    }

    /**
     * Field-by-field text for a record, in the order a person would read it.
     *
     * Only fields that carry meaning: identifiers, narrative and
     * classification. No timestamps, no foreign keys, no status columns
     * whose value is a single word — they inflate the index and dilute
     * every similarity score computed over it.
     *
     * @return array<int, string>
     */
    private function segments(Model $record, string $type): array
    {
        $fields = match ($type) {
            'control' => ['objective', 'description', 'control_documentation', 'notes', 'type', 'nature', 'frequency', 'coso_component'],
            'risk' => ['description', 'category', 'risk_type', 'risk_source', 'basel_event_type'],
            'policy' => ['description', 'policy_type', 'applies_to'],
            'obligation' => ['description', 'obligation_type', 'frequency', 'due_rule', 'penalty_description', 'legal_reference'],
            'framework_requirement' => ['description', 'guidance', 'requirement_type', 'suggested_evidence'],
            'incident' => ['description', 'incident_type', 'basel_event_type', 'root_cause', 'contributing_factors', 'lessons_learned'],
            'exception' => ['description', 'root_cause', 'remediation_plan', 'closure_notes', 'severity'],
            'document' => ['description', 'document_type'],
            default => ['description'],
        };

        $segments = [];

        foreach ($fields as $field) {
            $value = $record->{$field} ?? null;

            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map(
                    fn ($v) => is_scalar($v) ? (string) $v : null,
                    $value,
                )));
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $segments[] = ucfirst(str_replace('_', ' ', $field)).': '.$value;
            }
        }

        // A policy's substance is in its sections, not its summary row. The
        // relation already orders by sequence, which is the order a reader
        // would go through them in and therefore the right chunk order.
        if ($type === 'policy' && method_exists($record, 'sections')) {
            foreach ($record->sections as $section) {
                $body = trim((string) ($section->body ?? ''));

                if ($body !== '') {
                    $segments[] = trim(($section->heading ?? '')."\n".$body);
                }
            }
        }

        return $segments;
    }

    /**
     * Pack semantic segments into chunks near the target size, carrying an
     * overlap tail so a fact spanning a boundary is retrievable from either
     * side. A single oversized segment — a long policy section — becomes its
     * own chunk rather than being split mid-clause.
     *
     * @param  array<int, string>  $segments
     * @return array<int, string>
     */
    private function pack(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        $targetChars = max(400, (int) config('ai.retrieval.chunk_tokens', 800) * 4);
        $overlapChars = max(0, (int) config('ai.retrieval.chunk_overlap_tokens', 100) * 4);

        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($segment) + 1 > $targetChars) {
                $chunks[] = trim($current);
                $current = $overlapChars > 0 ? mb_substr($current, -$overlapChars)."\n" : '';
            }

            $current .= ($current === '' ? '' : "\n").$segment;
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    private function titleFor(Model $record, string $type): string
    {
        $reference = $this->referenceFor($record, $type);
        $title = (string) ($record->title ?? $record->name ?? '');

        return trim(($reference ? $reference.' — ' : '').$title) ?: $type.' #'.$record->getKey();
    }

    private function referenceFor(Model $record, string $type): ?string
    {
        return match ($type) {
            'control' => $record->control_ref,
            'risk' => $record->code,
            'policy' => $record->policy_ref,
            'obligation' => $record->obligation_ref,
            'framework_requirement' => $record->ref_code,
            'incident' => $record->incident_ref,
            'exception' => $record->reference,
            'document' => $record->reference,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function metadataFor(Model $record, string $type): array
    {
        return array_filter([
            'reference' => $this->referenceFor($record, $type),
            'status' => $record->status ?? null,
            'severity' => $record->severity ?? null,
            'owner_id' => $record->owner_id ?? $record->risk_owner_id ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Framework requirements hang off a framework rather than carrying a
     * tenant, and a framework may be a global one. Index those against the
     * framework's tenant where it has one; skip the global library, which
     * every tenant can already read through the framework explorer and which
     * would otherwise be duplicated per tenant.
     */
    private function tenantIdFor(Model $record): ?int
    {
        if (isset($record->tenant_id)) {
            return (int) $record->tenant_id;
        }

        if ($record instanceof FrameworkRequirement) {
            return $record->framework?->tenant_id ? (int) $record->framework->tenant_id : null;
        }

        return null;
    }

    /**
     * Content that must never enter the index.
     *
     * Confidential documents are gated per-document on an access-role list
     * (Phase 9), which a single permission_key cannot express — approximating
     * it would be a confidentiality regression. Investigation cases are
     * excluded structurally by not appearing in the source map at all, per
     * §C.5.
     */
    private function isExcluded(Model $record): bool
    {
        if ($record instanceof Document && $record->is_confidential) {
            return true;
        }

        // Draft and withdrawn policies are not the operative text; answering
        // a compliance question from a superseded policy is worse than not
        // answering it.
        if ($record instanceof Policy && in_array($record->status, ['Draft', 'Withdrawn', 'Archived'], true)) {
            return true;
        }

        return false;
    }

    /** Rough but stable. Used for budget display, never for billing. */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
