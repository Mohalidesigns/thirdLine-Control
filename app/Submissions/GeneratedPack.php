<?php

namespace App\Submissions;

/**
 * The output of one submission generator (13.4).
 *
 * A pack is a working document, not a snapshot: `sections` carry both what
 * the platform already knows and the fields a human still has to answer, and
 * `completeness` is the honest ratio of the two. Nothing here decides
 * anything — filing stays a human act behind maker-checker.
 */
final class GeneratedPack
{
    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<int, int>  $evidenceIds
     * @param  array<int, array<string, mixed>>  $unverifiedItems
     * @param  array<int, array<string, mixed>>  $validationErrors
     * @param  array<int, array<string, mixed>>  $signatories
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $evidenceIds = [],
        public readonly array $unverifiedItems = [],
        public readonly array $validationErrors = [],
        public readonly array $signatories = [],
        public readonly array $meta = [],
    ) {}

    /**
     * Completeness is required-fields-answered ÷ required-fields, over every
     * section. It is computed here rather than reported by each generator so
     * one pack type cannot flatter itself against another.
     *
     * An empty LIST from a system-derived field counts as answered: a breach
     * register with no breaches in it is a complete answer, not a gap. A
     * null or blank one does not — that is the platform expecting a value
     * and having none, which a filer needs to see.
     */
    public function completeness(): int
    {
        $required = 0;
        $answered = 0;

        foreach ($this->sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! ($field['required'] ?? false)) {
                    continue;
                }

                $required++;

                if ($this->isAnswered($field)) {
                    $answered++;
                }
            }
        }

        return $required === 0 ? 100 : (int) floor(($answered / $required) * 100);
    }

    private function isAnswered(array $field): bool
    {
        $value = $field['value'] ?? null;

        if (is_array($value)) {
            return $value !== [] || ($field['source'] ?? 'input') === 'system';
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    public function isBlocked(): bool
    {
        return $this->blockingErrors() !== [];
    }

    /** @return array<int, array<string, mixed>> */
    public function blockingErrors(): array
    {
        return array_values(array_filter(
            $this->validationErrors,
            fn (array $error) => (bool) ($error['blocking'] ?? true),
        ));
    }

    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'signatories' => $this->signatories,
            'meta' => $this->meta,
        ];
    }
}
