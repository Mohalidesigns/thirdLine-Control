@extends('reports.layout')

@section('title', 'Statement of Applicability')

@section('content')
    <h2>{{ $framework->name }}</h2>

    <div class="stat-row">
        <div class="stat"><div class="value">{{ $summary['total'] }}</div><div class="label">Requirements</div></div>
        <div class="stat"><div class="value">{{ $summary['applicable'] }}</div><div class="label">Applicable</div></div>
        <div class="stat"><div class="value">{{ $summary['excluded'] }}</div><div class="label">Excluded</div></div>
        <div class="stat"><div class="value">{{ $summary['implemented'] }}</div><div class="label">Implemented</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 8%;">Ref</th>
                <th style="width: 24%;">Control / requirement</th>
                <th style="width: 8%;">Applicable</th>
                <th style="width: 14%;">Implementation</th>
                <th style="width: 22%;">Justification</th>
                <th style="width: 24%;">Mapped controls</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement as $row)
                <tr>
                    <td class="mono">{{ $row['ref_code'] }}</td>
                    <td>{{ $row['title'] }}</td>
                    <td>{{ $row['is_applicable'] ? 'Yes' : 'No' }}</td>
                    <td>{{ str_replace('_', ' ', $row['implementation_status']) }}</td>
                    <td>{{ $row['justification'] ?? '—' }}</td>
                    <td>
                        @forelse ($row['controls'] as $control)
                            <span class="mono">{{ $control['control_ref'] }}</span> ({{ $control['coverage'] }})@if (! $loop->last), @endif
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
