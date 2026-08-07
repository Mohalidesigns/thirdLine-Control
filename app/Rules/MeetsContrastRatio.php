<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * WCAG 2.x contrast: the colour must reach the given ratio against white
 * (Phase 7.3 rule — brand colours carry white text in the shell, so
 * anything below 4.5:1 is illegible and rejected).
 */
class MeetsContrastRatio implements ValidationRule
{
    public function __construct(private float $minimumRatio = 4.5) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            $fail('The :attribute must be a hex colour like #0B1F3A.');

            return;
        }

        $ratio = $this->contrastAgainstWhite($value);

        if ($ratio < $this->minimumRatio) {
            $fail(sprintf(
                'The :attribute fails accessibility contrast: %.2f:1 against white text — at least %.1f:1 is required. Choose a darker colour.',
                $ratio,
                $this->minimumRatio,
            ));
        }
    }

    private function contrastAgainstWhite(string $hex): float
    {
        $luminance = $this->relativeLuminance($hex);

        // White has relative luminance 1.0.
        return (1.0 + 0.05) / ($luminance + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $channels = array_map(
            fn (string $channel) => hexdec($channel) / 255,
            str_split(ltrim($hex, '#'), 2),
        );

        [$r, $g, $b] = array_map(
            fn (float $c) => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
