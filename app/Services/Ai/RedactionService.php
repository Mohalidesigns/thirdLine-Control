<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * PII redaction (Part C §C.5, R5). Deterministic and regex-driven, never
 * model-based — a redactor you have to trust an LLM to run is not a control.
 *
 * Applied to the assembled context AND the user's free text, as a mandatory
 * pipeline stage in AiGateway. There is no per-request switch: the only way
 * to disable it is the installation-wide AI_REDACTION_ENABLED, which exists
 * for a residency review, not for convenience.
 *
 * The reverse map is held in memory for the life of one request so citations
 * and entity references can be rehydrated in the UI after the response
 * returns. It is never persisted and never sent.
 */
class RedactionService
{
    /** @var array<string, string> placeholder => original, this request only */
    private array $map = [];

    /** @var array<string, string> original => placeholder, for stability */
    private array $reverse = [];

    private int $personCounter = 0;

    /** @var array<int, string>|null lazily loaded lowercase user names */
    private ?array $knownNames = null;

    /**
     * Redact a string. Placeholders are stable within a request: the same
     * account number seen twice yields the same token, so the model can
     * still reason about "the same customer" without ever seeing who.
     */
    public function redact(?string $text): string
    {
        $text = (string) $text;

        if ($text === '' || ! $this->enabled()) {
            return $text;
        }

        // Names first. They are the only pass driven by tenant data rather
        // than shape, and running them before the digit patterns avoids a
        // name that contains digits being partly eaten.
        $text = $this->redactNames($text);

        foreach ((array) config('ai.redaction.patterns', []) as $label => $pattern) {
            $text = $this->replacePattern($text, $pattern, $label);
        }

        if (config('ai.redaction.redact_amounts')) {
            $text = $this->replacePattern($text, (string) config('ai.redaction.amount_pattern'), 'AMOUNT');
        }

        return $text;
    }

    /**
     * Redact a structured payload: every string value at any depth, and
     * every value whose KEY is on the excluded list, whatever it contains.
     * The key check catches the address field that holds no digits and the
     * customer name that no user row matches.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function redactArray(array $data): array
    {
        $excluded = array_map('strtolower', (array) config('ai.redaction.excluded_attributes', []));
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $excluded, true)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redact($value),
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * Put the real values back for display. Applied to model output before a
     * human reads it, so a draft reads naturally even though the model only
     * ever saw placeholders.
     */
    public function rehydrate(?string $text): string
    {
        $text = (string) $text;

        return $this->map === [] ? $text : strtr($text, $this->map);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function rehydrateArray(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                is_array($value) => $this->rehydrateArray($value),
                is_string($value) => $this->rehydrate($value),
                default => $value,
            };
        }

        return $data;
    }

    /**
     * True when the text still contains something that matches a configured
     * pattern. The gateway asserts this on the fully assembled payload as a
     * last line before egress: if redaction somehow missed something, the
     * call is abandoned rather than sent.
     */
    public function containsSensitive(string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        foreach ((array) config('ai.redaction.patterns', []) as $label => $pattern) {
            if (! preg_match_all($pattern, $text, $matches)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                if ($this->shouldReplace($label, $match)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The request-scoped reverse map. Never persist the result of this. */
    public function map(): array
    {
        return $this->map;
    }

    /** Between capability runs, so placeholders never leak across subjects. */
    public function reset(): void
    {
        $this->map = [];
        $this->reverse = [];
        $this->personCounter = 0;
    }

    private function enabled(): bool
    {
        return (bool) config('ai.redaction.enabled', true);
    }

    private function replacePattern(string $text, string $pattern, string $label): string
    {
        return (string) preg_replace_callback($pattern, function (array $m) use ($label) {
            $value = $m[0];

            if (! $this->shouldReplace($label, $value)) {
                return $value;
            }

            return $this->placeholderFor($value, $label);
        }, $text);
    }

    /**
     * The card-number pattern deliberately matches any 13-19 digit run, so a
     * Luhn check decides whether a match is really a PAN. Without it every
     * sixteen-digit reference number in the estate would be redacted into
     * uselessness.
     */
    private function shouldReplace(string $label, string $value): bool
    {
        if ($label !== 'PAN' || ! config('ai.redaction.luhn_check_pan', true)) {
            return true;
        }

        return $this->passesLuhn(preg_replace('/\D/', '', $value) ?? '');
    }

    private function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * People. Sourced from the tenant's own user table — the names that
     * actually appear in exception notes, test comments and case activity.
     * Longest first so "Adaeze Okonkwo" is not half-matched by "Adaeze".
     */
    private function redactNames(string $text): string
    {
        foreach ($this->names() as $name) {
            if (stripos($text, $name) === false) {
                continue;
            }

            $text = (string) preg_replace(
                '/\b'.preg_quote($name, '/').'\b/i',
                $this->placeholderFor($name, 'PERSON'),
                $text,
            );
        }

        return $text;
    }

    /** @return array<int, string> */
    private function names(): array
    {
        if ($this->knownNames !== null) {
            return $this->knownNames;
        }

        $names = User::query()
            ->whereNotNull('name')
            ->pluck('name')
            ->flatMap(fn (string $full) => [$full, ...array_filter(
                explode(' ', $full),
                // Single tokens shorter than four characters redact half the
                // English language; full names always match regardless.
                fn (string $part) => mb_strlen($part) >= 4,
            )])
            ->unique()
            ->sortByDesc(fn (string $n) => mb_strlen($n))
            ->values()
            ->all();

        return $this->knownNames = $names;
    }

    /**
     * Stable placeholder allocation. People get numbered so relationships
     * survive redaction; everything else collapses to a single token per
     * distinct value.
     */
    private function placeholderFor(string $value, string $label): string
    {
        $normalised = $label === 'PERSON' ? mb_strtolower($value) : $value;
        $cacheKey = $label.':'.$normalised;

        if (isset($this->reverse[$cacheKey])) {
            return $this->reverse[$cacheKey];
        }

        $placeholder = $label === 'PERSON'
            ? '[PERSON_'.(++$this->personCounter).']'
            : '['.$label.']';

        $this->reverse[$cacheKey] = $placeholder;

        // Only person placeholders are reversible for display. Collapsing
        // every account number to "[ACCOUNT]" is intentional and one-way —
        // rehydrating them would put the value back on a screen and into a
        // PDF export, which is exactly what R5 is for.
        if ($label === 'PERSON') {
            $this->map[$placeholder] = $value;
        }

        return $placeholder;
    }
}
