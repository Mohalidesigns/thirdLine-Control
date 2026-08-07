<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $template['name'] }}</title>
    <style>
        @php
            $brandPrimary = $branding->primary_colour ?? '#0B1F3A';
            $brandAccent = $branding->accent_colour ?? '#C9A227';
        @endphp
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #2D3748; margin: 0; }
        .header { background: {{ $brandPrimary }}; color: #fff; padding: 18px 24px; }
        .header h1 { margin: 0; font-size: 16px; }
        .header p { margin: 3px 0 0; font-size: 9px; color: {{ $brandAccent }}; text-transform: uppercase; letter-spacing: 1px; }
        .meta { padding: 10px 24px; background: #F7FAFC; border-bottom: 1px solid #E2E8F0; font-size: 9px; color: #718096; }
        .content { padding: 16px 24px; }
        h2 { font-size: 12px; color: {{ $brandPrimary }}; border-bottom: 2px solid {{ $brandAccent }}; padding-bottom: 4px; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; }
        th { background: {{ $brandPrimary }}; color: #fff; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; }
        td { padding: 5px 6px; border-bottom: 1px solid #E2E8F0; vertical-align: top; }
        tr:nth-child(even) td { background: #F7FAFC; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8px; font-weight: bold; }
        .sev-Critical { background: #FED7D7; color: #C53030; }
        .sev-High { background: #FEEBC8; color: #C05621; }
        .sev-Medium { background: #FEFCBF; color: #975A16; }
        .sev-Low { background: #C6F6D5; color: #2D7D46; }
        .stat-row { width: 100%; margin-bottom: 12px; }
        .stat { display: inline-block; width: 23%; background: #F7FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 8px; margin-right: 1%; text-align: center; }
        .stat .value { font-size: 18px; font-weight: bold; color: #0B1F3A; }
        .stat .label { font-size: 7.5px; text-transform: uppercase; color: #718096; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 8px 24px; font-size: 8px; color: #A0AEC0; border-top: 1px solid #E2E8F0; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8.5px; }
    </style>
</head>
<body>
    <div class="header">
        @if (! empty($branding?->report_header_html))
            {!! $branding->report_header_html !!}
        @else
            <h1>{{ $branding->product_name ?? $template['header']['title'] ?? config('app.name') }} — @yield('title')</h1>
            <p>{{ $template['header']['subtitle'] ?? 'Atheris Control Solution' }}</p>
        @endif
    </div>
    <div class="meta">
        Generated {{ now()->format('d M Y H:i') }} by {{ auth()->user()?->name ?? 'System' }}
    </div>
    <div class="content">
        @yield('content')
    </div>
    <div class="footer">
        @if (! empty($branding?->report_footer_html))
            {!! $branding->report_footer_html !!}
        @else
            {{ $template['footer']['text'] ?? 'Confidential' }}
        @endif
    </div>
</body>
</html>
