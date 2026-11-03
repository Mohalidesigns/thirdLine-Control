<?php

namespace App\Reports;

/**
 * Inline SVG charts for the PDF and HTML engines. dompdf cannot lay out
 * flexbox or canvas, but it draws SVG handed to it as an image, so the
 * pie, column and line charts are built here as plain strings and embedded
 * as data URIs. Every chart section still carries its table view, so none
 * of these has to be readable on its own.
 */
class ChartSvg
{
    private const FONT = 'DejaVu Sans, sans-serif';

    /**
     * Donut with a direct-labelled legend: label, value and share per slice.
     *
     * @param  array<int, array{label: string, value: float|int, colour: string}>  $slices
     */
    public static function donut(array $slices, string $total = 'Total', int $width = 520, int $height = 190): string
    {
        $slices = array_values(array_filter($slices, fn ($s) => (float) $s['value'] > 0));
        $sum = array_sum(array_map(fn ($s) => (float) $s['value'], $slices));

        $cx = 95;
        $cy = $height / 2;
        $outer = 78;
        $inner = 46;
        $body = '';

        if ($sum <= 0) {
            $body .= self::ring($cx, $cy, $outer, $inner, 0, 359.99, '#E2E8F0');
        } else {
            $angle = 0.0;
            foreach ($slices as $slice) {
                $sweep = ((float) $slice['value'] / $sum) * 360;
                $body .= self::ring($cx, $cy, $outer, $inner, $angle, min($angle + $sweep, $angle + 359.99), $slice['colour']);
                $angle += $sweep;
            }
        }

        $body .= self::text($cx, $cy + 2, self::fmt($sum), 20, '#1A365D', 'middle', true);
        $body .= self::text($cx, $cy + 17, strtoupper($total), 7, '#718096', 'middle');

        $y = max(24, ($height - count($slices) * 24) / 2 + 12);
        foreach ($slices as $slice) {
            $share = $sum > 0 ? round((float) $slice['value'] / $sum * 100) : 0;
            $body .= sprintf('<rect x="205" y="%s" width="10" height="10" rx="2" fill="%s"/>', $y - 9, $slice['colour']);
            $body .= self::text(223, $y, $slice['label'], 10, '#2D3748');
            $body .= self::text($width - 70, $y, self::fmt($slice['value']), 10, '#1A365D', 'end', true);
            $body .= self::text($width - 12, $y, $share.'%', 9, '#718096', 'end');
            $y += 24;
        }

        return self::wrap($body, $width, $height);
    }

    /**
     * Vertical columns, grouped when there is more than one series. This is
     * also the histogram: ordered buckets on the x axis, counts on the y.
     *
     * @param  array<int, string>  $categories
     * @param  array<int, array{label: string, values: array<int, float|int>, colours: array<int, string>}>  $series
     */
    public static function columns(array $categories, array $series, string $unit = '', int $width = 520, int $height = 200): string
    {
        $left = 34;
        $right = 8;
        $top = 16;
        $bottom = 34;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $max = 0.0;
        foreach ($series as $one) {
            foreach ($one['values'] as $value) {
                $max = max($max, (float) $value);
            }
        }
        $max = self::niceMax($max);

        $body = '';
        for ($i = 0; $i <= 4; $i++) {
            $y = $top + $plotH - ($plotH * $i / 4);
            $body .= sprintf('<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="#E2E8F0" stroke-width="1"/>', $left, $y, $width - $right, $y);
            $body .= self::text($left - 5, $y + 3, self::fmt($max * $i / 4).($unit === 'percent' ? '%' : ''), 7, '#A0AEC0', 'end');
        }

        $groupW = $plotW / max(1, count($categories));
        $barW = min(34, ($groupW * 0.62) / max(1, count($series)));

        foreach ($categories as $index => $label) {
            $groupX = $left + $groupW * $index + ($groupW - $barW * count($series)) / 2;

            foreach ($series as $seriesIndex => $one) {
                $value = (float) ($one['values'][$index] ?? 0);
                $h = $max > 0 ? ($value / $max) * $plotH : 0;
                $x = $groupX + $barW * $seriesIndex;
                $y = $top + $plotH - $h;

                if ($h > 0) {
                    $body .= sprintf(
                        '<rect x="%s" y="%s" width="%s" height="%s" rx="2" fill="%s"/>',
                        $x + 1, $y, max(1, $barW - 2), $h, $one['colours'][$index] ?? '#1A365D',
                    );
                }

                $body .= self::text($x + $barW / 2, $y - 4, self::fmt($value).($unit === 'percent' ? '%' : ''), 8, '#2D3748', 'middle', true);
            }

            $body .= self::text($left + $groupW * ($index + 0.5), $height - $bottom + 14, self::clip($label, (int) max(8, $groupW / 5)), 8, '#718096', 'middle');
        }

        $body .= sprintf('<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="#A0AEC0" stroke-width="1"/>', $left, $top + $plotH, $width - $right, $top + $plotH);

        if (count($series) > 1) {
            $lx = $left;
            foreach ($series as $one) {
                $body .= sprintf('<rect x="%s" y="%d" width="8" height="8" rx="2" fill="%s"/>', $lx, $height - 9, $one['colours'][0] ?? '#1A365D');
                $body .= self::text($lx + 12, $height - 2, $one['label'], 8, '#718096');
                $lx += 24 + strlen($one['label']) * 5;
            }
        }

        return self::wrap($body, $width, $height);
    }

    /**
     * Line chart, one polyline per series, with a dot and value at each point.
     *
     * @param  array<int, string>  $categories
     * @param  array<int, array{label: string, values: array<int, float|int>, colour: string}>  $series
     */
    public static function lines(array $categories, array $series, string $unit = '', int $width = 520, int $height = 200): string
    {
        $left = 34;
        $right = 12;
        $top = 16;
        $bottom = 34;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $max = 0.0;
        foreach ($series as $one) {
            foreach ($one['values'] as $value) {
                $max = max($max, (float) $value);
            }
        }
        $max = self::niceMax($max);

        $body = '';
        for ($i = 0; $i <= 4; $i++) {
            $y = $top + $plotH - ($plotH * $i / 4);
            $body .= sprintf('<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="#E2E8F0" stroke-width="1"/>', $left, $y, $width - $right, $y);
            $body .= self::text($left - 5, $y + 3, self::fmt($max * $i / 4), 7, '#A0AEC0', 'end');
        }

        $step = count($categories) > 1 ? $plotW / (count($categories) - 1) : 0;
        $xAt = fn (int $i) => count($categories) > 1 ? $left + $step * $i : $left + $plotW / 2;

        foreach ($categories as $index => $label) {
            $body .= self::text($xAt($index), $height - $bottom + 14, self::clip($label, 10), 8, '#718096', 'middle');
        }

        foreach ($series as $one) {
            $points = [];
            foreach ($one['values'] as $index => $value) {
                $points[] = sprintf('%s,%s', $xAt($index), $top + $plotH - ($max > 0 ? ((float) $value / $max) * $plotH : 0));
            }

            if (count($points) > 1) {
                $body .= sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="2"/>', implode(' ', $points), $one['colour']);
            }

            foreach ($points as $index => $point) {
                [$px, $py] = explode(',', $point);
                $body .= sprintf('<circle cx="%s" cy="%s" r="3" fill="%s"/>', $px, $py, $one['colour']);
                $body .= self::text((float) $px, (float) $py - 7, self::fmt($one['values'][$index] ?? 0), 8, '#2D3748', 'middle', true);
            }
        }

        if (count($series) > 1) {
            $lx = $left;
            foreach ($series as $one) {
                $body .= sprintf('<rect x="%s" y="%d" width="8" height="8" rx="2" fill="%s"/>', $lx, $height - 9, $one['colour']);
                $body .= self::text($lx + 12, $height - 2, $one['label'], 8, '#718096');
                $lx += 24 + strlen($one['label']) * 5;
            }
        }

        return self::wrap($body, $width, $height);
    }

    /** The `src` of an <img> that dompdf and browsers both accept. */
    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function wrap(string $body, int $width, int $height): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">%s</svg>',
            $width, $height, $width, $height, $body,
        );
    }

    /** A ring segment as a polygon: plain line segments render the same in every SVG engine. */
    private static function ring(float $cx, float $cy, float $outer, float $inner, float $from, float $to, string $colour): string
    {
        $steps = max(2, (int) ceil(($to - $from) / 4));
        $outerPts = [];
        $innerPts = [];

        for ($i = 0; $i <= $steps; $i++) {
            $rad = deg2rad($from + ($to - $from) * $i / $steps - 90);
            $outerPts[] = sprintf('%.2f,%.2f', $cx + $outer * cos($rad), $cy + $outer * sin($rad));
            $innerPts[] = sprintf('%.2f,%.2f', $cx + $inner * cos($rad), $cy + $inner * sin($rad));
        }

        return sprintf(
            '<polygon points="%s" fill="%s" stroke="#FFFFFF" stroke-width="1.5"/>',
            implode(' ', array_merge($outerPts, array_reverse($innerPts))), $colour,
        );
    }

    private static function text(float $x, float $y, string $content, int $size, string $fill, string $anchor = 'start', bool $bold = false): string
    {
        return sprintf(
            '<text x="%s" y="%s" font-family="%s" font-size="%d" fill="%s" text-anchor="%s"%s>%s</text>',
            round($x, 1), round($y, 1), self::FONT, $size, $fill, $anchor,
            $bold ? ' font-weight="bold"' : '', htmlspecialchars($content, ENT_XML1 | ENT_QUOTES),
        );
    }

    private static function fmt(float|int|string $value): string
    {
        $value = (float) $value;

        return number_format($value, fmod($value, 1) === 0.0 ? 0 : 1);
    }

    private static function clip(string $label, int $max): string
    {
        return mb_strlen($label) > $max ? mb_substr($label, 0, $max - 1).'…' : $label;
    }

    private static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 4;
        }

        $magnitude = 10 ** floor(log10($max));
        foreach ([1, 2, 4, 5, 8, 10] as $step) {
            if ($max <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }
}
