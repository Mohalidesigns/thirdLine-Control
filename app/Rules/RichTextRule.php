<?php

namespace App\Rules;

use App\Support\RichText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an incoming Editor.js document: the block structure must be
 * well-formed and every block type must belong to the field's toolset
 * preset — an unknown type is rejected, never silently stored.
 */
class RichTextRule implements ValidationRule
{
    public function __construct(private string $preset = 'default') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! array_key_exists('blocks', $value) || ! is_array($value['blocks'])) {
            $fail('The :attribute is not a valid rich-text document.');

            return;
        }

        if (count($value['blocks']) > 500) {
            $fail('The :attribute has too many blocks.');

            return;
        }

        $allowed = RichText::PRESETS[$this->preset] ?? RichText::PRESETS['default'];

        foreach ($value['blocks'] as $index => $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                $fail("Block {$index} of the :attribute is malformed.");

                return;
            }

            if (! in_array($block['type'], $allowed, true)) {
                $fail("The :attribute contains an unsupported block type '{$block['type']}'.");

                return;
            }

            if (isset($block['data']) && ! is_array($block['data'])) {
                $fail("Block {$index} of the :attribute is malformed.");

                return;
            }
        }
    }
}
