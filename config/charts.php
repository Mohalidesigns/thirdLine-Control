<?php

/*
|--------------------------------------------------------------------------
| Chart palette — server-side mirror of the CSS tokens
|--------------------------------------------------------------------------
|
| The authoritative declaration of these values for the browser is the
| `--chart-*` custom property block in resources/css/app.css; this file is
| the same palette for the renderers that cannot read CSS — dompdf, PhpWord
| and the PowerPoint writer. Change one, change the other.
|
| The categorical order is not cosmetic. It was validated for colour-vision
| separation on the AEGIS card surface (#FFFFFF): worst adjacent pair
| ΔE 9.1 under protanopia and 19.6 unsimulated, both above the gates. Three
| slots sit below 3:1 contrast on white, which is why every chart in this
| product ships a table view and direct labels — that relief is a
| requirement, not a nicety.
|
| Status tones are reserved: they mean good → critical and are never used
| for series identity. They always travel with a text label, never colour
| alone.
|
*/

return [

    'categorical' => [
        1 => '#2A78D6', // blue
        2 => '#EB6834', // orange
        3 => '#1BAF7A', // aqua
        4 => '#EDA100', // yellow
        5 => '#E87BA4', // magenta
        6 => '#008300', // green
        7 => '#4A3AA7', // violet
        8 => '#E34948', // red
    ],

    /* One hue, light → dark. Magnitude only — never identity. */
    'sequential' => [
        '#CDE2FB', '#9EC5F4', '#6DA7EC', '#3987E5', '#256ABF', '#184F95', '#0D366B',
    ],

    /* Ordered buckets (ageing bands, tiers): the light end still clears 2:1. */
    'ordinal' => ['#86B6EF', '#5598E7', '#2A78D6', '#1C5CAB', '#104281'],

    'status' => [
        'good' => '#0CA30C',
        'warning' => '#FAB219',
        'serious' => '#EC835A',
        'critical' => '#D03B3B',
        'muted' => '#A0AEC0',
    ],

    'ink' => [
        'primary' => '#2D3748',
        'secondary' => '#718096',
        'muted' => '#A0AEC0',
        'grid' => '#E2E8F0',
        'surface' => '#FFFFFF',
    ],

];
