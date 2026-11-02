@php
    $brandPrimary = $branding->primary_colour ?? '#1A365D';
    $brandAccent = $branding->accent_colour ?? '#C9A227';
    $categorical = array_values($palette['categorical']);
    $statusTones = $palette['status'];

    /*
     * Chart marks in a PDF: dompdf has no flexbox and no SVG, so a bar is a
     * table row with a filled cell whose width is a percentage. That keeps
     * the mark spec intact — thin bars, rounded at the data end and square
     * at the baseline, hairline rules, no border around the fill — and every
     * chart is followed by its table view, which is what makes the lighter
     * palette slots legal on a white surface.
     */
    $ordinal = array_values($palette['ordinal']);

    $sliceColour = function (array $series, int $index) use ($categorical, $statusTones, $ordinal) {
        if (! empty($series['tones'][$index])) {
            return $statusTones[$series['tones'][$index]] ?? $statusTones['muted'];
        }

        return ! empty($series['ordinal']) ? $ordinal[$index % count($ordinal)] : $categorical[$index % count($categorical)];
    };

    $barColour = function (array $series, int $index) use ($categorical, $statusTones, $ordinal) {
        // A severity or rating scale keeps its reserved status tone; an
        // ordered scale (ageing bands) takes the one-hue ordinal ramp so the
        // order is visible in the colour; everything else is series identity.
        if (! empty($series['tones'][$index])) {
            return $statusTones[$series['tones'][$index]] ?? $statusTones['muted'];
        }

        if (! empty($series['ordinal'])) {
            return $ordinal[$index % count($ordinal)];
        }

        return $categorical[((($series['slot'] ?? 1) - 1) % count($categorical))];
    };
@endphp
    <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #2D3748; margin: 0; }
        .band { background: {{ $brandPrimary }}; color: #fff; padding: 22px 28px 20px; }
        .band table { border-collapse: collapse; }
        .band td.logo { width: 30px; height: 30px; text-align: center; vertical-align: middle; border-radius: 6px; background: {{ $brandAccent }}; color: {{ $brandPrimary }}; font-size: 11px; font-weight: bold; }
        .band .brand { font-size: 12px; font-weight: bold; padding-left: 10px; }
        .band .tag { font-size: 7px; letter-spacing: 1.6px; color: #A9B8D0; padding-left: 10px; text-transform: uppercase; }
        .band .goldrule { height: 2px; background: {{ $brandAccent }}; margin: 16px 0 16px; }
        .band .kicker { font-size: 8px; letter-spacing: 1.8px; color: {{ $brandAccent }}; text-transform: uppercase; }
        .band h1 { margin: 5px 0 12px; font-size: 20px; line-height: 1.2; }
        .band .bandmeta td { padding-right: 26px; vertical-align: top; }
        .band .bandmeta .k { font-size: 7px; letter-spacing: 1.2px; color: #A9B8D0; text-transform: uppercase; }
        .band .bandmeta .v { font-size: 10px; font-weight: bold; margin-top: 2px; }
        .band p { margin: 3px 0 0; font-size: 8.5px; color: {{ $brandAccent }}; text-transform: uppercase; letter-spacing: 1px; }
        .infogrid { width: 100%; border-collapse: collapse; border: 1px solid #E2E8F0; margin: 16px 0 0; }
        .infogrid td { width: 33.33%; padding: 9px 12px; border: 1px solid #E2E8F0; vertical-align: top; }
        .infogrid .k { font-size: 7px; letter-spacing: 1px; color: #718096; text-transform: uppercase; }
        .infogrid .v { font-size: 10px; font-weight: bold; color: {{ $brandPrimary }}; margin-top: 3px; }
        .figure { margin: 4px 0 8px; text-align: center; }
        .content { padding: 4px 28px 44px; }
        h2 { font-size: 11px; letter-spacing: 0.8px; text-transform: uppercase; color: {{ $brandPrimary }}; border-left: 4px solid {{ $brandAccent }}; padding: 1px 0 1px 8px; margin: 20px 0 9px; }
        p.body { margin: 0 0 8px; line-height: 1.5; }
        table.data { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
        table.data th { background: {{ $brandPrimary }}; color: #fff; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; }
        table.data td { padding: 5px 6px; border-bottom: 1px solid #E2E8F0; vertical-align: top; }
        table.data tr:nth-child(even) td { background: #F7FAFC; }

        /* Cover */
        .cover { text-align: center; padding: 150px 40px 0; }
        .cover h1 { font-size: 30px; color: {{ $brandPrimary }}; margin: 0 0 6px; }
        .cover .sub { font-size: 13px; color: {{ $brandAccent }}; margin-bottom: 36px; }
        .cover table { margin: 0 auto; border-collapse: collapse; }
        .cover td { padding: 5px 12px; font-size: 10px; }
        .cover td.label { color: #718096; text-align: right; }
        .classification { margin-top: 40px; font-size: 10px; font-weight: bold; color: #C53030; letter-spacing: 1px; text-transform: uppercase; }

        /* KPI row */
        .kpi { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin-bottom: 10px; }
        .kpi td { background: #F7FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 9px 10px; vertical-align: top; }
        .kpi td { text-align: center; }
        .kpi .value { font-size: 22px; font-weight: bold; color: {{ $brandPrimary }}; }
        .kpi .label { font-size: 7.5px; text-transform: uppercase; color: #718096; letter-spacing: 0.4px; }
        .kpi .caption { font-size: 7.5px; color: #A0AEC0; margin-top: 2px; }

        /* Chart */
        table.chart { width: 100%; border-collapse: collapse; margin: 4px 0 10px; }
        table.chart td { padding: 3px 0; vertical-align: middle; }
        td.chart-label { width: 27%; padding-right: 8px; font-size: 8.5px; color: #718096; }
        td.chart-value { width: 12%; padding-left: 8px; font-size: 9px; font-weight: bold; text-align: right; }
        .bar { height: 12px; border-radius: 0 4px 4px 0; }
        .legend { font-size: 8px; color: #718096; margin: 0 0 6px; }
        .legend span { margin-right: 10px; }
        .swatch { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 3px; }

        .signature { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .signature td { width: 50%; padding: 26px 18px 6px; vertical-align: bottom; }
        .rule { border-top: 1px solid #718096; padding-top: 4px; font-size: 9px; }
        .rule .role { color: #718096; font-size: 8px; }
        .break { page-break-after: always; }
        .note { font-size: 8px; color: #A0AEC0; margin: 2px 0 10px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 6px 28px; font-size: 7.5px; color: #A0AEC0; border-top: 1px solid #E2E8F0; }
    </style>
</head>
<body>
@php $hasCover = false; @endphp

@unless ($hasCover)
    @php
        $brandName = $branding?->product_name ?? $document['header']['title'] ?? config('app.name');
        $initials = collect(preg_split('/[\s\-—]+/', (string) $brandName))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        $typeLabel = str_replace('_', ' ', $document['report_type'] ?? 'management');
    @endphp
    <div class="band">
        @if (! empty($branding?->report_header_html))
            {!! $branding->report_header_html !!}
        @else
            <table>
                <tr>
                    <td class="logo">{{ $initials }}</td>
                    <td>
                        <div class="brand">{{ $brandName }}</div>
                        <div class="tag">{{ $document['header']['subtitle'] ?? $document['context']['tenant'] ?? '' }}</div>
                    </td>
                </tr>
            </table>
            <div class="goldrule"></div>
            <div class="kicker">{{ $typeLabel }} report</div>
            <h1>{{ $document['title'] ?? '' }}</h1>
            <table class="bandmeta">
                <tr>
                    <td><div class="k">Report no.</div><div class="v">{{ $document['code'] ?? '—' }}</div></td>
                    <td><div class="k">Issue date</div><div class="v">{{ $document['generated_at'] ?? '' }}</div></td>
                    <td><div class="k">Version</div><div class="v">v{{ $document['version'] ?? 1 }}</div></td>
                </tr>
            </table>
        @endif
    </div>
    <div class="content" style="padding-bottom: 0;">
        <table class="infogrid">
            <tr>
                <td><div class="k">Entity</div><div class="v">{{ $document['context']['entity'] ?? '—' }}</div></td>
                <td><div class="k">Period</div><div class="v">{{ $document['context']['period'] ?? '—' }}</div></td>
                <td><div class="k">Classification</div><div class="v">{{ $document['confidentiality'] ?? '' }}</div></td>
            </tr>
            <tr>
                <td><div class="k">Prepared by</div><div class="v">{{ $document['generated_by'] ?? '—' }}</div></td>
                <td><div class="k">Organisation</div><div class="v">{{ $document['context']['tenant'] ?? '—' }}</div></td>
                <td><div class="k">Framework</div><div class="v">{{ $document['context']['framework'] ?? '—' }}</div></td>
            </tr>
        </table>
    </div>
@endunless

<div class="content">
    @foreach ($document['sections'] as $section)
        @switch($section['type'])

            @case('cover')
                {{-- The cover's facts (entity, period, author, classification) live in the header band and info grid. --}}
                @break

            @case('toc')
                <h2>{{ $section['title'] }}</h2>
                <table class="data">
                    <tbody>
                    @foreach ($document['sections'] as $entry)
                        @continue(in_array($entry['type'], ['cover', 'toc', 'page_break'], true) || ! ($entry['title'] ?? ''))
                        <tr><td>{{ $entry['title'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
                @break

            @case('narrative')
            @case('appendix')
                @if ($section['title'])
                    <h2>{{ $section['title'] }}</h2>
                @endif
                @foreach (preg_split('/\n{2,}/', $section['body'] ?? '') as $paragraph)
                    @if (trim($paragraph) !== '')
                        <p class="body">{{ trim($paragraph) }}</p>
                    @endif
                @endforeach
                @break

            @case('kpi_row')
                @if ($section['title'])
                    <h2>{{ $section['title'] }}</h2>
                @endif
                @if (count($section['items']))
                    <table class="kpi">
                        <tr>
                            @foreach ($section['items'] as $item)
                                <td>
                                    <div class="value">{{ $item['value'] }}</div>
                                    <div class="label">{{ $item['label'] }}</div>
                                    @if ($item['caption'])
                                        <div class="caption">{{ $item['caption'] }}</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </table>
                @endif
                @break

            @case('chart')
                <h2>{{ $section['title'] }}</h2>
                @php
                    $payload = $section['data'];
                    $categories = $payload['categories'] ?? [];
                    $series = $payload['series'] ?? [];
                    $max = 0;
                    foreach ($series as $one) { foreach ($one['values'] as $value) { $max = max($max, (float) $value); } }
                    $max = $max ?: 1;
                @endphp

                @php
                    $chartType = $section['chart_type'] ?? 'bar';
                    $labels = array_map(fn ($c) => (string) $c['label'], $categories);
                    $unit = $payload['unit'] ?? '';
                    $svg = null;

                    if (count($categories) && count($series)) {
                        if (in_array($chartType, ['pie', 'donut'], true)) {
                            $first = $series[0];
                            $svg = \App\Reports\ChartSvg::donut(
                                array_map(fn ($label, $i) => [
                                    'label' => $label,
                                    'value' => $first['values'][$i] ?? 0,
                                    'colour' => $sliceColour($first, $i),
                                ], $labels, array_keys($labels)),
                                $first['label'] ?? 'Total',
                            );
                        } elseif (in_array($chartType, ['column', 'histogram', 'stacked_column'], true)) {
                            $svg = \App\Reports\ChartSvg::columns($labels, array_map(fn ($one, $n) => [
                                'label' => $one['label'],
                                'values' => $one['values'],
                                'colours' => array_map(fn ($i) => count($series) > 1
                                    ? $categorical[((($one['slot'] ?? $n + 1) - 1) % count($categorical))]
                                    : $sliceColour($one, $i), array_keys($labels)),
                            ], $series, array_keys($series)), $unit);
                        } elseif ($chartType === 'line') {
                            $svg = \App\Reports\ChartSvg::lines($labels, array_map(fn ($one, $n) => [
                                'label' => $one['label'],
                                'values' => $one['values'],
                                'colour' => $categorical[((($one['slot'] ?? $n + 1) - 1) % count($categorical))],
                            ], $series, array_keys($series)), $unit);
                        }
                    }
                @endphp

                @if ($svg)
                    <div class="figure"><img src="{{ \App\Reports\ChartSvg::dataUri($svg) }}" style="width: 100%;" alt="{{ $section['title'] }}"></div>
                @endif

                @if (! $svg && count($series) > 1)
                    <p class="legend">
                        @foreach ($series as $one)
                            <span>
                                <span class="swatch" style="background: {{ $categorical[((($one['slot'] ?? $loop->iteration) - 1) % count($categorical))] }}"></span>{{ $one['label'] }}
                            </span>
                        @endforeach
                    </p>
                @endif

                @if (! $svg && count($categories))
                    <table class="chart">
                        @foreach ($categories as $index => $category)
                            @foreach ($series as $seriesIndex => $one)
                                <tr>
                                    <td class="chart-label">{{ $seriesIndex === 0 ? $category['label'] : '' }}{{ count($series) > 1 ? ' · '.$one['label'] : '' }}</td>
                                    <td>
                                        <div class="bar" style="width: {{ max(1, round(((float) ($one['values'][$index] ?? 0) / $max) * 100)) }}%; background: {{ $barColour($one, $index) }};"></div>
                                    </td>
                                    <td class="chart-value">{{ is_numeric($one['values'][$index] ?? null) ? number_format((float) $one['values'][$index], ($payload['unit'] ?? '') === 'percent' ? 1 : 0) : '—' }}{{ ($payload['unit'] ?? '') === 'percent' ? '%' : '' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </table>
                @endif

                @if ($section['caption'] ?? ($payload['caption'] ?? null))
                    <p class="note">{{ $section['caption'] ?? $payload['caption'] }}</p>
                @endif

                @include('reports.partials.table', ['table' => $section['table']])
                @break

            @case('table')
                <h2>{{ $section['title'] }}</h2>
                @if ($section['data']['caption'] ?? null)
                    <p class="note">{{ $section['data']['caption'] }}</p>
                @endif
                @include('reports.partials.table', ['table' => $section['table']])
                @break

            @case('page_break')
                <div class="break"></div>
                @break

            @case('signature_block')
                <h2>{{ $section['title'] }}</h2>
                <table class="signature">
                    @foreach (array_chunk($section['signatories'], 2) as $pair)
                        <tr>
                            @foreach ($pair as $signatory)
                                <td>
                                    <div class="rule">
                                        {{ ($signatory['name'] ?? null) ?: ' ' }}
                                        <div class="role">{{ $signatory['role'] ?? '' }}</div>
                                        <div class="role">Date: ____________________</div>
                                    </div>
                                </td>
                            @endforeach
                            @if (count($pair) === 1)
                                <td></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
                @break

        @endswitch
    @endforeach
</div>

<div class="footer">
    @if (! empty($branding?->report_footer_html))
        {!! $branding->report_footer_html !!}
    @else
        {{ $document['footer']['text'] ?? $document['confidentiality'] }} — {{ $document['context']['tenant'] ?? '—' }}
    @endif
</div>
</body>
</html>
