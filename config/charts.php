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
| The categorical order is the Atheris brand palette, shared with ThirdLine
| so a chart reads the same in both products. Every chart ships a table
| view and direct labels, so identity never rests on hue alone.
|
| Status tones are reserved: they mean good → critical and are never used
| for series identity. They always travel with a text label, never colour
| alone.
|
*/

return [

    'categorical' => [
        1 => '#1A365D', // navy
        2 => '#D4AF37', // gold
        3 => '#2D7D46', // green
        4 => '#C53030', // red
        5 => '#319795', // teal
        6 => '#DD6B20', // orange
        7 => '#7C3AED', // violet
        8 => '#94A3B8', // slate
    ],

    /* One hue (brand navy), light → dark. Magnitude only — never identity. */
    'sequential' => [
        '#D9E2EF', '#B3C4DD', '#7F9AC0', '#4A6D9E', '#2A4E7E', '#1A365D', '#0F2340',
    ],

    /* Ordered buckets (ageing bands, tiers). */
    'ordinal' => ['#B3C4DD', '#7F9AC0', '#4A6D9E', '#1A365D', '#0F2340'],

    'status' => [
        'good' => '#2D7D46',
        'warning' => '#D97706',
        'serious' => '#EA580C',
        'critical' => '#DC2626',
        'muted' => '#94A3B8',
    ],

    'ink' => [
        'primary' => '#2D3748',
        'secondary' => '#64748B',
        'muted' => '#94A3B8',
        'grid' => '#E2E8F0',
        'surface' => '#FFFFFF',
    ],

];
