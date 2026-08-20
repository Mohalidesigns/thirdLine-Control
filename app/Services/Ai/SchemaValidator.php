<?php

namespace App\Services\Ai;

/**
 * Validates parsed model output against a prompt's output_schema
 * (Part C §C.4 step 9).
 *
 * A deliberately small JSON Schema subset — object, array, string, number,
 * integer, boolean, required, enum, nested properties and items. That is
 * everything the nine capability contracts in §C.6 use, and a validator you
 * can read in one sitting is worth more here than one that handles $ref.
 *
 * Where the model supports structured outputs the same schema also goes on
 * the wire, so this is the second line rather than the only one. It stays
 * regardless: a provider guarantee is not a control we own.
 */
class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string> empty when valid
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        if ($schema === []) {
            return [];
        }

        $type = $schema['type'] ?? null;

        if ($type !== null && ! $this->matchesType($value, $type)) {
            return [sprintf('%s: expected %s, got %s.', $path, $type, get_debug_type($value))];
        }

        return match ($type) {
            'object' => $this->validateObject($value, $schema, $path),
            'array' => $this->validateArray($value, $schema, $path),
            default => $this->validateScalar($value, $schema, $path),
        };
    }

    /**
     * Best-effort extraction of a JSON object from a text reply.
     *
     * Models occasionally wrap JSON in a fenced block or a sentence of
     * preamble even when asked not to. Recovering from that is not the same
     * as accepting malformed output — whatever comes back here still has to
     * pass validate().
     */
    public function extractJson(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $direct = json_decode($text, true);

        if (is_array($direct)) {
            return $direct;
        }

        // Fenced block, with or without a language tag.
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $fenced = json_decode(trim($m[1]), true);

            if (is_array($fenced)) {
                return $fenced;
            }
        }

        // First balanced object in the text.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $slice = json_decode(substr($text, $start, $end - $start + 1), true);

            if (is_array($slice)) {
                return $slice;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateObject(mixed $value, array $schema, string $path): array
    {
        $errors = [];
        $properties = (array) ($schema['properties'] ?? []);

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $value)) {
                $errors[] = sprintf('%s.%s: required property is missing.', $path, $required);
            }
        }

        foreach ($properties as $name => $propertySchema) {
            if (! array_key_exists($name, $value) || $value[$name] === null) {
                continue;
            }

            $errors = [...$errors, ...$this->validate($value[$name], (array) $propertySchema, $path.'.'.$name)];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateArray(mixed $value, array $schema, string $path): array
    {
        $errors = [];
        $items = (array) ($schema['items'] ?? []);

        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            $errors[] = sprintf('%s: expected at least %d item(s), got %d.', $path, $schema['minItems'], count($value));
        }

        if ($items === []) {
            return $errors;
        }

        foreach (array_values($value) as $index => $item) {
            $errors = [...$errors, ...$this->validate($item, $items, $path.'['.$index.']')];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateScalar(mixed $value, array $schema, string $path): array
    {
        $enum = (array) ($schema['enum'] ?? []);

        if ($enum !== [] && ! in_array($value, $enum, true)) {
            return [sprintf('%s: "%s" is not one of [%s].', $path, is_scalar($value) ? $value : get_debug_type($value), implode(', ', array_map('strval', $enum)))];
        }

        if (isset($schema['minimum']) && is_numeric($value) && $value < $schema['minimum']) {
            return [sprintf('%s: %s is below the minimum of %s.', $path, $value, $schema['minimum'])];
        }

        if (isset($schema['maximum']) && is_numeric($value) && $value > $schema['maximum']) {
            return [sprintf('%s: %s is above the maximum of %s.', $path, $value, $schema['maximum'])];
        }

        return [];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && (array_is_list($value) === false || $value === []),
            'array' => is_array($value) && (array_is_list($value) || $value === []),
            'string' => is_string($value),
            // JSON has one number type; a model returning 4 for a "number"
            // field is correct, and rejecting it would be pedantry.
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'integer' => is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-'))),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
