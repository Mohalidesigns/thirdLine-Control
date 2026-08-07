@extends('reports.layout')

@section('title', $spotCheck->reference)

@section('content')
    @foreach ($template['sections'] as $section)
        @if ($section['type'] === 'scope')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <tr><td style="width: 25%"><strong>Reference</strong></td><td class="mono">{{ $spotCheck->reference }}</td></tr>
                <tr><td><strong>Title</strong></td><td>{{ $spotCheck->title }}</td></tr>
                <tr><td><strong>Scope</strong></td><td>{{ $spotCheck->scope_description ?: '—' }}</td></tr>
                <tr><td><strong>Unit / Branch</strong></td><td>{{ $spotCheck->unit?->name ?? '—' }}</td></tr>
                <tr><td><strong>Process</strong></td><td>{{ $spotCheck->process?->name ?? '—' }}</td></tr>
                <tr><td><strong>Date conducted</strong></td><td>{{ $spotCheck->date_conducted?->format('d M Y') }}</td></tr>
                <tr><td><strong>Conducted by</strong></td><td>{{ $spotCheck->conductor?->name ?? '—' }}</td></tr>
                <tr><td><strong>Unannounced</strong></td><td>{{ $spotCheck->is_surprise ? 'Yes' : 'No' }}</td></tr>
            </table>
        @elseif ($section['type'] === 'findings')
            <h2>{{ $section['title'] }} ({{ $spotCheck->findings->count() }})</h2>
            @forelse ($spotCheck->findings as $i => $finding)
                <table>
                    <tr>
                        <td style="width: 25%"><strong>Finding {{ $i + 1 }}</strong>
                            <span class="badge sev-{{ $finding->severity }}">{{ $finding->severity }}</span>
                        </td>
                        <td>{{ $finding->observation }}</td>
                    </tr>
                    @if ($finding->control)
                        <tr><td><strong>Control</strong></td><td class="mono">{{ $finding->control->control_ref }} — {{ $finding->control->title }}</td></tr>
                    @endif
                    @if ($finding->risk_implication)
                        <tr><td><strong>Risk implication</strong></td><td>{{ $finding->risk_implication }}</td></tr>
                    @endif
                    @if ($finding->recommendation)
                        <tr><td><strong>Recommendation</strong></td><td>{{ $finding->recommendation }}</td></tr>
                    @endif
                    @if ($finding->management_response)
                        <tr><td><strong>Management response</strong></td><td>{{ $finding->management_response }}</td></tr>
                    @endif
                    @if ($finding->agreed_action)
                        <tr><td><strong>Agreed action</strong></td>
                            <td>{{ $finding->agreed_action }}
                                @if ($finding->responsible_party) — {{ $finding->responsible_party->name }} @endif
                                @if ($finding->target_date) (by {{ $finding->target_date->format('d M Y') }}) @endif
                            </td>
                        </tr>
                    @endif
                </table>
            @empty
                <p>No findings were raised during this spot check.</p>
            @endforelse
        @elseif ($section['type'] === 'sign_off')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <tr>
                    <td style="width: 50%; height: 50px;"><strong>Conducted by</strong><br><br>{{ $spotCheck->conductor?->name }}</td>
                    <td><strong>Control Function Head</strong><br><br>______________________</td>
                </tr>
            </table>
        @endif
    @endforeach
@endsection
