@extends('reports.layout')

@section('title', 'Board & Committee Pack')

@section('content')
    @if (isset($metrics))
        <h2>Executive Summary</h2>
        <div class="stat-row">
            <div class="stat"><div class="value">{{ $metrics['exceptions']['open'] }}</div><div class="label">Open exceptions</div></div>
            <div class="stat"><div class="value">{{ $metrics['exceptions']['overdue'] }}</div><div class="label">Overdue</div></div>
            <div class="stat"><div class="value">{{ $metrics['exceptions']['unresolvedCriticalHigh'] }}</div><div class="label">Critical / High open</div></div>
            <div class="stat"><div class="value">{{ $metrics['testing']['completionRate'] }}%</div><div class="label">Testing completion</div></div>
        </div>
        <table>
            <thead><tr><th>Severity</th><th>Open</th><th>Ageing 0–30</th><th>31–60</th><th>61–90</th><th>90+</th></tr></thead>
            <tbody>
                <tr>
                    <td>
                        Critical: {{ $metrics['exceptions']['bySeverity']['Critical'] }} ·
                        High: {{ $metrics['exceptions']['bySeverity']['High'] }} ·
                        Medium: {{ $metrics['exceptions']['bySeverity']['Medium'] }} ·
                        Low: {{ $metrics['exceptions']['bySeverity']['Low'] }}
                    </td>
                    <td>{{ $metrics['exceptions']['open'] }}</td>
                    <td>{{ $metrics['exceptions']['ageing']['0-30'] }}</td>
                    <td>{{ $metrics['exceptions']['ageing']['31-60'] }}</td>
                    <td>{{ $metrics['exceptions']['ageing']['61-90'] }}</td>
                    <td>{{ $metrics['exceptions']['ageing']['90+'] }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if (isset($exceptions))
        <h2>Open Exceptions by Severity</h2>
        <table>
            <thead><tr><th>Ref</th><th>Exception</th><th>Control</th><th>Severity</th><th>Owner</th><th>Age</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($exceptions as $exception)
                    <tr>
                        <td class="mono">{{ $exception->reference }}</td>
                        <td>{{ Str::limit($exception->title, 55) }}</td>
                        <td class="mono">{{ $exception->control?->control_ref ?? '—' }}</td>
                        <td><span class="badge sev-{{ $exception->severity }}">{{ $exception->severity }}</span></td>
                        <td>{{ $exception->owner?->name ?? 'Unassigned' }}</td>
                        <td>{{ $exception->age_days }}d</td>
                        <td>{{ $exception->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (isset($controls))
        <h2>Control Effectiveness</h2>
        <table>
            <thead><tr><th>Ref</th><th>Control</th><th>Owner</th><th>Overall rating</th></tr></thead>
            <tbody>
                @foreach ($controls as $row)
                    <tr>
                        <td class="mono">{{ $row['control']->control_ref }}</td>
                        <td>{{ $row['control']->title }}</td>
                        <td>{{ $row['control']->owner?->name ?? '—' }}</td>
                        <td>{{ $row['rating'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (isset($compensating))
        <h2>Active Compensating Controls</h2>
        <table>
            <thead><tr><th>Description</th><th>Covers</th><th>Owner</th><th>Duration</th><th>Expires</th></tr></thead>
            <tbody>
                @foreach ($compensating as $comp)
                    <tr>
                        <td>{{ Str::limit($comp->description, 70) }}</td>
                        <td class="mono">{{ $comp->primaryControl?->control_ref ?? '—' }}</td>
                        <td>{{ $comp->owner?->name ?? '—' }}</td>
                        <td>{{ $comp->is_temporary ? 'Temporary' : 'Permanent' }}</td>
                        <td>{{ $comp->effective_to?->format('d M Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
