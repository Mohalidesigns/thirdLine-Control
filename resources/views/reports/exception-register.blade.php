@extends('reports.layout')

@section('title', 'Exception Register')

@section('content')
    @foreach ($template['sections'] as $section)
        @if ($section['type'] === 'summary')
            <h2>{{ $section['title'] }}</h2>
            <div class="stat-row">
                <div class="stat"><div class="value">{{ $exceptions->count() }}</div><div class="label">In report</div></div>
                <div class="stat"><div class="value">{{ $exceptions->whereIn('status', ['Open','Assigned','In Progress','Remediated'])->count() }}</div><div class="label">Open</div></div>
                <div class="stat"><div class="value">{{ $exceptions->where('is_overdue', true)->count() }}</div><div class="label">Overdue</div></div>
                <div class="stat"><div class="value">{{ $exceptions->whereIn('severity', ['Critical','High'])->count() }}</div><div class="label">Critical / High</div></div>
            </div>
        @elseif ($section['type'] === 'register')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Ref</th><th>Exception</th><th>Control</th><th>Severity</th>
                        <th>Owner</th><th>Raised</th><th>Target</th><th>Age</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($exceptions as $exception)
                        <tr>
                            <td class="mono">{{ $exception->reference }}</td>
                            <td>{{ Str::limit($exception->title, 60) }}</td>
                            <td class="mono">{{ $exception->control?->control_ref ?? '—' }}</td>
                            <td><span class="badge sev-{{ $exception->severity }}">{{ $exception->severity }}</span></td>
                            <td>{{ $exception->owner?->name ?? 'Unassigned' }}</td>
                            <td>{{ $exception->date_raised?->format('d M Y') }}</td>
                            <td>{{ $exception->target_closure_date?->format('d M Y') ?? '—' }}{{ $exception->is_overdue ? ' *' : '' }}</td>
                            <td>{{ $exception->age_days }}d</td>
                            <td>{{ $exception->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="font-size: 8px; color: #718096;">* past target closure date</p>
        @endif
    @endforeach
@endsection
