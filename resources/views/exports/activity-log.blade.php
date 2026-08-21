<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Activity Log Export</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #1f2937; margin: 24px; }
        h1 { color: #1A365D; font-size: 16px; margin: 0 0 2px; }
        .tagline { color: #6b7280; font-size: 9px; margin: 0 0 10px; }
        .meta { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 8px 10px; margin-bottom: 12px; }
        .meta strong { color: #1A365D; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1A365D; color: #fff; text-align: left; padding: 5px 6px; font-size: 8px; text-transform: uppercase; }
        td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; vertical-align: top; word-break: break-all; }
        tr:nth-child(even) td { background: #f9fafb; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>Activity Log — Evidence Pack</h1>
    <p class="tagline">Every user action on the application — logins, CRUD, workflow transitions.</p>

    <div class="meta">
        <strong>Generated:</strong> {{ $generatedAt->toDayDateTimeString() }} by {{ $generatedBy }} &nbsp;|&nbsp;
        <strong>Rows:</strong> {{ $entries->count() }}@if($total > $cap) of {{ $total }} matching (capped at {{ $cap }} — use the CSV export for the full set)@endif
        <br>
        <strong>Applied filters:</strong>
        @forelse($filters as $key => $value)
            {{ $key }} = "{{ $value }}"@if(!$loop->last), @endif
        @empty
            none (full log)
        @endforelse
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:11%">When</th>
                <th style="width:14%">Actor</th>
                <th style="width:12%">Event</th>
                <th style="width:16%">Subject</th>
                <th style="width:27%">Description</th>
                <th style="width:10%">IP</th>
                <th style="width:10%">Device</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($entry['created_at'])->format('Y-m-d H:i:s') }}</td>
                    <td>
                        {{ $entry['user']['name'] ?? 'System' }}
                        @if(!empty($entry['user']['email']))<br><span class="muted">{{ $entry['user']['email'] }}</span>@endif
                    </td>
                    <td>{{ $entry['event_label'] }}</td>
                    <td>
                        @if($entry['subject_type'])
                            {{ $entry['subject_type'] }}<br>
                            <span class="muted">{{ $entry['subject_label'] ?? ('#'.$entry['subject_id']) }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $entry['description'] ?? '—' }}</td>
                    <td>{{ $entry['ip_address'] ?? '—' }}</td>
                    <td>{{ $entry['device_name'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
