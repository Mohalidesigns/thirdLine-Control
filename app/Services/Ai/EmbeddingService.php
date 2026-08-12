<?php

namespace App\Services\Ai;

/**
 * Vector representation for retrieval ranking.
 *
 * The default driver is local and deterministic: a hashed bag-of-terms
 * projected into a fixed-width unit vector, cosine-scored in PHP over a
 * keyword-prefiltered candidate set, exactly as Part C §C.7 permits. It
 * needs no model at all, so indexing works even while the Ollama server is
 * down or busy generating.
 *
 * Be honest about what that is. It is lexical, not semantic: it will not
 * connect "dual authorisation" to "four-eyes principle" the way a trained
 * model would. Keyword recall does the heavy lifting in RetrievalService and
 * this supplies the ranking and the term-overlap signal. The local Ollama
 * server can serve a real embedding model (granite-embedding) without any
 * data leaving the boundary — adopting it is a second driver here plus a
 * re-index, with nothing above this class changing.
 */
class EmbeddingService
{
    /**
     * @return array<int, float> unit-normalised vector
     */
    public function embed(string $text): array
    {
        return match (config('ai.embeddings.driver', 'hash')) {
            default => $this->hashEmbed($text),
        };
    }

    /**
     * Cosine similarity. Both vectors are already unit length, so this is
     * the dot product; the guard covers a legacy row indexed under different
     * dimensions, which scores zero rather than throwing.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * ($b[$i] ?? 0.0);
        }

        return max(-1.0, min(1.0, $dot));
    }

    /**
     * Term frequency hashed into fixed buckets, then L2-normalised.
     *
     * Two hashes per term with opposing signs (the signed hashing trick)
     * keeps collisions from systematically inflating similarity: a collision
     * is as likely to cancel as to add.
     *
     * @return array<int, float>
     */
    private function hashEmbed(string $text): array
    {
        $dimensions = max(32, (int) config('ai.embeddings.dimensions', 256));
        $vector = array_fill(0, $dimensions, 0.0);

        foreach ($this->terms($text) as $term => $count) {
            // Sub-linear term frequency: a word repeated forty times in a
            // policy section should not swamp the rest of it.
            $weight = 1 + log($count);

            $hash = crc32($term);
            $bucket = $hash % $dimensions;
            $sign = (crc32('sign:'.$term) % 2) === 0 ? 1.0 : -1.0;

            $vector[$bucket] += $sign * $weight;
        }

        return $this->normalise($vector);
    }

    /**
     * @return array<string, int> term => frequency
     */
    public function terms(string $text): array
    {
        $minLength = max(1, (int) config('ai.embeddings.min_term_length', 3));

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < $minLength || isset(self::STOP_WORDS[$token])) {
                continue;
            }

            $terms[$token] = ($terms[$token] ?? 0) + 1;
        }

        return $terms;
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(fn (float $v) => round($v / $magnitude, 6), $vector);
    }

    /**
     * Domain-aware stop list. The usual English filler plus the words that
     * appear in every single GRC record and therefore carry no signal here —
     * "control" in a control library discriminates nothing.
     */
    private const STOP_WORDS = [
        'the' => 1, 'and' => 1, 'for' => 1, 'are' => 1, 'but' => 1, 'not' => 1,
        'you' => 1, 'all' => 1, 'can' => 1, 'her' => 1, 'was' => 1, 'one' => 1,
        'our' => 1, 'out' => 1, 'has' => 1, 'had' => 1, 'his' => 1, 'she' => 1,
        'they' => 1, 'this' => 1, 'that' => 1, 'with' => 1, 'from' => 1,
        'have' => 1, 'been' => 1, 'were' => 1, 'their' => 1, 'which' => 1,
        'there' => 1, 'when' => 1, 'what' => 1, 'will' => 1, 'would' => 1,
        'should' => 1, 'could' => 1, 'shall' => 1, 'must' => 1, 'into' => 1,
        'such' => 1, 'than' => 1, 'then' => 1, 'these' => 1, 'those' => 1,
        'other' => 1, 'each' => 1, 'any' => 1, 'may' => 1, 'also' => 1,
        'control' => 1, 'controls' => 1,
    ];
}
