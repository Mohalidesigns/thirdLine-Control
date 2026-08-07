@extends('reports.layout')

@section('title', 'Control Testing Summary')

@section('content')
    @foreach ($template['sections'] as $section)
        @if ($section['type'] === 'metrics')
            <h2>{{ $section['title'] }}</h2>
            <div class="stat-row">
                <div class="stat"><div class="value">{{ $metrics['testing']['total'] }}</div><div class="label">Total tests</div></div>
                <div class="stat"><div class="value">{{ $metrics['testing']['completionRate'] }}%</div><div class="label">Completion</div></div>
                <div class="stat"><div class="value">{{ $metrics['testing']['overdue'] }}</div><div class="label">Overdue</div></div>
                <div class="stat"><div class="value">{{ $metrics['effectiveness']['Effective'] }}</div><div class="label">Effective controls</div></div>
            </div>
        @elseif ($section['type'] === 'results')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Ref</th><th>Control</th><th>Period</th><th>Tester</th>
                        <th>Due</th><th>Status</th><th>Overall rating</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($instances as $instance)
                        <tr>
                            <td class="mono">{{ $instance->reference }}</td>
                            <td>{{ Str::limit($instance->control?->title, 45) }}</td>
                            <td>{{ $instance->period_label }}</td>
                            <td>{{ $instance->tester?->name ?? 'Unassigned' }}</td>
                            <td>{{ $instance->due_date?->format('d M Y') }}</td>
                            <td>{{ $instance->status }}</td>
                            <td>{{ $instance->effectivenessRating?->overall_rating ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endsection
