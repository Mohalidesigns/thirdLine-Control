<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeChunk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Permission-filtered retrieval (Part C §C.7).
 *
 * The single most important guarantee in this phase: a user must never
 * receive a synthesised answer built from records they cannot open. That is
 * enforced by filtering on permission_key IN SQL, in the candidate query,
 * before ranking and long before any text reaches a prompt. Not by trimming
 * results afterwards — an unreadable record is never a row this class sees.
 *
 * Ranking is hybrid: keyword recall (FULLTEXT on MySQL, LIKE elsewhere)
 * fused with vector similarity by reciprocal rank fusion, then a relevance
 * floor. When nothing clears the floor the result is empty, and an empty
 * result is passed to the model as exactly that — it is what makes
 * "I don't have that in your data" an honest answer rather than a fallback.
 */
class RetrievalService
{
    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * @param  array<int, string>  $sources  source_type keys, empty for all
     * @return Collection<int, array{chunk: AiKnowledgeChunk, score: float}>
     */
    public function retrieve(User $user, string $query, array $sources = [], ?int $topK = null): Collection
    {
        $topK ??= (int) config('ai.retrieval.top_k', 12);
        $permissions = $this->permissionsFor($user);

        if ($permissions === [] || trim($query) === '') {
            return collect();
        }

        $candidates = $this->candidates($user, $query, $sources, $permissions);

        if ($candidates->isEmpty()) {
            return collect();
        }

        return $this->rank($candidates, $query, $topK);
    }

    /**
     * Retrieval scoped to one record and what surrounds it — used by the
     * capabilities that already know their subject, where a whole-corpus
     * search would only add noise.
     *
     * @return Collection<int, array{chunk: AiKnowledgeChunk, score: float}>
     */
    public function retrieveForSubject(User $user, string $sourceType, int $sourceId, string $query, array $sources = [], ?int $topK = null): Collection
    {
        $permissions = $this->permissionsFor($user);

        if ($permissions === []) {
            return collect();
        }

        $own = AiKnowledgeChunk::query()
            ->permittedTo($permissions)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('chunk_index')
            ->get()
            ->map(fn (AiKnowledgeChunk $chunk) => ['chunk' => $chunk, 'score' => 1.0]);

        $related = $this->retrieve($user, $query, $sources, $topK)
            ->reject(fn (array $hit) => $hit['chunk']->source_type === $sourceType
                && (int) $hit['chunk']->source_id === $sourceId);

        return $own->concat($related)->values();
    }

    /**
     * The context block handed to the prompt.
     *
     * Every entry carries the identifiers a citation resolves back to, so
     * the model can only cite records that were actually retrieved — and
     * AiGateway drops any citation whose id is not in this list, which makes
     * a fabricated reference impossible to surface rather than merely
     * unlikely.
     *
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     * @return array<int, array<string, mixed>>
     */
    public function toContext(Collection $hits, RedactionService $redaction): array
    {
        return $hits->map(fn (array $hit) => [
            'record_type' => $hit['chunk']->source_type,
            'record_id' => (int) $hit['chunk']->source_id,
            'reference' => $hit['chunk']->metadata['reference'] ?? null,
            'title' => $redaction->redact((string) $hit['chunk']->title),
            'content' => $redaction->redact($hit['chunk']->content),
        ])->values()->all();
    }

    /**
     * Identifiers only, for ai_interactions.retrieved_context (§C.3).
     * Never content: the chunks are re-derivable from their source records,
     * and copying tenant text into the audit log would widen the blast
     * radius of that table for no gain.
     *
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     * @return array<int, array{type: string, id: int, chunk: int, score: float}>
     */
    public function toContextIds(Collection $hits): array
    {
        return $hits->map(fn (array $hit) => [
            'type' => $hit['chunk']->source_type,
            'id' => (int) $hit['chunk']->source_id,
            'chunk' => (int) $hit['chunk']->chunk_index,
            'score' => round($hit['score'], 4),
        ])->values()->all();
    }

    /**
     * Turn the model's claimed citations into records the user can open.
     *
     * A citation survives only if it names a record that was in the
     * retrieved set for this call. Anything else is dropped — including a
     * real record the user could open, because a model that cites something
     * it was not shown is guessing, and a plausible citation is more
     * dangerous than none.
     *
     * @param  array<int, array<string, mixed>>  $claimed
     * @param  Collection<int, array{chunk: AiKnowledgeChunk, score: float}>  $hits
     * @return array<int, array<string, mixed>>
     */
    public function resolveCitations(array $claimed, Collection $hits): array
    {
        $available = $hits->mapWithKeys(fn (array $hit) => [
            $hit['chunk']->source_type.':'.$hit['chunk']->source_id => $hit['chunk'],
        ]);

        $resolved = [];
        $seen = [];

        foreach ($claimed as $citation) {
            $type = (string) ($citation['record_type'] ?? '');
            $id = (int) ($citation['record_id'] ?? 0);
            $key = $type.':'.$id;

            if ($id === 0 || isset($seen[$key]) || ! $available->has($key)) {
                continue;
            }

            $chunk = $available->get($key);
            $definition = (array) config("ai.retrieval.sources.{$type}", []);
            $seen[$key] = true;

            $resolved[] = [
                'record_type' => $type,
                'record_id' => $id,
                'label' => $definition['label'] ?? $type,
                'reference' => $chunk->metadata['reference'] ?? null,
                'title' => $chunk->title,
                'route' => $definition['route'] ?? null,
                'claim' => $citation['claim'] ?? null,
            ];
        }

        return $resolved;
    }

    /**
     * How much of the answer is actually grounded. Feeds confidence scoring:
     * a reply citing one chunk out of twelve retrieved is a weaker claim
     * than one drawing on most of what it was given.
     */
    public function coverage(array $resolvedCitations, Collection $hits): float
    {
        if ($hits->isEmpty()) {
            return 0.0;
        }

        $distinctRetrieved = $hits
            ->map(fn (array $hit) => $hit['chunk']->source_type.':'.$hit['chunk']->source_id)
            ->unique()
            ->count();

        return min(1.0, round(count($resolvedCitations) / max(1, $distinctRetrieved), 3));
    }

    /**
     * The permissions the retrieval filter matches against — the user's
     * effective Spatie permissions, intersected with the permission keys
     * that retrieval sources actually declare. The intersection is not
     * cosmetic: it keeps an unrelated permission from ever widening the
     * filter if a source's key is later mistyped.
     *
     * @return array<int, string>
     */
    public function permissionsFor(User $user): array
    {
        $declared = collect(config('ai.retrieval.sources', []))
            ->pluck('permission')
            ->filter()
            ->unique()
            ->all();

        return array_values(array_intersect(
            $user->getAllPermissions()->pluck('name')->all(),
            $declared,
        ));
    }

    /**
     * Candidate pool: permission-filtered, tenant-scoped, keyword-narrowed.
     *
     * @param  array<int, string>  $sources
     * @param  array<int, string>  $permissions
     * @return Collection<int, AiKnowledgeChunk>
     */
    private function candidates(User $user, string $query, array $sources, array $permissions): Collection
    {
        $pool = max(20, (int) config('ai.retrieval.candidate_pool', 200));

        $base = AiKnowledgeChunk::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->permittedTo($permissions)
            ->fromSources($sources);

        $keyword = $this->applyKeywordSearch(clone $base, $query)->limit($pool)->get();

        // A keyword miss must not mean no answer — an unusual phrasing
        // should still reach the vector pass. Top up from the same
        // permission-filtered query, never a wider one.
        if ($keyword->count() >= $pool) {
            return $keyword;
        }

        $topUp = $base->clone()
            ->when($keyword->isNotEmpty(), fn (Builder $q) => $q->whereNotIn('id', $keyword->pluck('id')))
            ->orderByDesc('indexed_at')
            ->limit($pool - $keyword->count())
            ->get();

        return $keyword->concat($topUp);
    }

    /**
     * MySQL gets a real FULLTEXT match; SQLite (the test connection) and
     * anything else fall back to LIKE over the extracted terms. Same
     * semantics, different speed — and the fallback only ever runs against
     * fixture-sized data.
     */
    private function applyKeywordSearch(Builder $query, string $search): Builder
    {
        $terms = array_keys($this->embeddings->terms($search));

        if ($terms === []) {
            return $query->orderByDesc('indexed_at');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $boolean = implode(' ', array_map(fn (string $t) => '+'.$t.'*', array_slice($terms, 0, 12)));

            return $query->whereRaw('MATCH(title, content) AGAINST (? IN BOOLEAN MODE)', [$boolean])
                ->orderByRaw('MATCH(title, content) AGAINST (? IN BOOLEAN MODE) DESC', [$boolean]);
        }

        return $query->where(function (Builder $q) use ($terms) {
            foreach (array_slice($terms, 0, 12) as $term) {
                $q->orWhere('content', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%');
            }
        })->orderByDesc('indexed_at');
    }

    /**
     * Reciprocal rank fusion of the keyword order and the vector order.
     *
     * RRF rather than a weighted score sum because the two signals are not
     * on a comparable scale — a FULLTEXT relevance and a cosine similarity
     * mean different things, and fusing by rank sidesteps having to invent
     * a conversion factor.
     *
     * @param  Collection<int, AiKnowledgeChunk>  $candidates
     * @return Collection<int, array{chunk: AiKnowledgeChunk, score: float}>
     */
    private function rank(Collection $candidates, string $query, int $topK): Collection
    {
        $k = (int) config('ai.retrieval.rrf_k', 60);
        $floor = (float) config('ai.retrieval.relevance_floor', 0.05);
        $queryVector = $this->embeddings->embed($query);
        $queryTerms = $this->embeddings->terms($query);

        // Keyword rank is the order the candidate query already returned.
        $scores = [];

        foreach ($candidates->values() as $position => $chunk) {
            $scores[$chunk->id] = ['chunk' => $chunk, 'rrf' => 1 / ($k + $position + 1)];
        }

        $bySimilarity = $candidates
            ->map(fn (AiKnowledgeChunk $chunk) => [
                'chunk' => $chunk,
                'similarity' => $this->embeddings->similarity($queryVector, (array) ($chunk->embedding ?? [])),
            ])
            ->sortByDesc('similarity')
            ->values();

        foreach ($bySimilarity as $position => $entry) {
            $id = $entry['chunk']->id;
            $scores[$id]['rrf'] += 1 / ($k + $position + 1);
            $scores[$id]['similarity'] = $entry['similarity'];
        }

        $best = max(1e-9, max(array_column($scores, 'rrf')));

        return collect($scores)
            ->map(fn (array $entry) => [
                'chunk' => $entry['chunk'],
                // Normalised to the best hit so the floor means the same
                // thing whatever the pool size.
                'score' => round($entry['rrf'] / $best, 4),
                'similarity' => round($entry['similarity'] ?? 0.0, 4),
            ])
            // A chunk sharing no term with the query and scoring nothing on
            // similarity is noise the model will try to use. Drop it.
            ->reject(fn (array $hit) => $hit['similarity'] <= 0.0
                && ! $this->sharesTerm($hit['chunk'], $queryTerms))
            ->sortByDesc('score')
            ->filter(fn (array $hit) => $hit['score'] >= $floor)
            ->take($topK)
            ->values();
    }

    /** @param array<string, int> $queryTerms */
    private function sharesTerm(AiKnowledgeChunk $chunk, array $queryTerms): bool
    {
        if ($queryTerms === []) {
            return false;
        }

        $haystack = mb_strtolower($chunk->title.' '.$chunk->content);

        foreach (array_keys($queryTerms) as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }
}
