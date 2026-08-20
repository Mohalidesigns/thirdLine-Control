@extends('reports.layout')

@section('title', 'Exception Manager Register')

@section('content')
    @foreach ($template['sections'] as $section)
        @if ($section['type'] === 'summary')
            <h2>{{ $section['title'] }}</h2>
            <div class="stat-row">
                <div class="stat"><div class="value">{{ $escalations->count() }}</div><div class="label">In report</div></div>
                <div class="stat"><div class="value">{{ $escalations->whereIn('status', ['Issued','Acknowledged','Reissued'])->count() }}</div><div class="label">Awaiting response</div></div>
                <div class="stat"><div class="value">{{ $escalations->where('is_response_late', true)->count() }}</div><div class="label">Late / overdue</div></div>
                <div class="stat"><div class="value">{{ $escalations->where('round_no', '>', 1)->count() }}</div><div class="label">Re-issued</div></div>
                <div class="stat"><div class="value">{{ $escalations->where('status', 'Closed')->count() }}</div><div class="label">Closed</div></div>
            </div>
        @elseif ($section['type'] === 'register')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Ref</th><th>Exception</th><th>Department</th><th>Respondent</th>
                        <th>Severity</th><th>Round</th><th>Issued</th><th>Due</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($escalations as $escalation)
                        <tr>
                            <td class="mono">{{ $escalation->reference }}</td>
                            <td class="mono">{{ $escalation->exception?->reference }}</td>
                            <td>{{ $escalation->targetLabel() }}</td>
                            <td>{{ $escalation->respondent?->name ?? $escalation->external_email ?? '—' }}</td>
                            <td><span class="badge sev-{{ $escalation->severity }}">{{ $escalation->severity }}</span></td>
                            <td>{{ $escalation->round_no }}</td>
                            <td>{{ $escalation->issued_at?->format('d M Y') }}</td>
                            <td>{{ $escalation->response_due_at?->format('d M Y') }}{{ $escalation->is_response_late ? ' *' : '' }}</td>
                            <td>{{ $escalation->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="font-size: 8px; color: #718096;">* response past its SLA due date</p>
        @elseif ($section['type'] === 'scorecard')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Department</th><th>Issued</th><th>Ack on time</th><th>Responded on time</th>
                        <th>Avg response (days)</th><th>Open overdue</th><th>Closure rate</th><th>Re-issue rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scorecard as $row)
                        <tr>
                            <td>{{ $row['department'] }}</td>
                            <td>{{ $row['issued'] }}</td>
                            <td>{{ $row['acknowledged_on_time_pct'] !== null ? $row['acknowledged_on_time_pct'].'%' : '—' }}</td>
                            <td>{{ $row['responded_on_time_pct'] !== null ? $row['responded_on_time_pct'].'%' : '—' }}</td>
                            <td>{{ $row['average_response_days'] ?? '—' }}</td>
                            <td>{{ $row['open_overdue'] }}</td>
                            <td>{{ $row['closure_rate_pct'] }}%</td>
                            <td>{{ $row['reissue_rate_pct'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @elseif ($section['type'] === 'ageing')
            <h2>{{ $section['title'] }}</h2>
            <table>
                <thead>
                    <tr><th>Department</th><th>Severity</th><th>0-30</th><th>31-60</th><th>61-90</th><th>90+</th></tr>
                </thead>
                <tbody>
                    @foreach ($ageing as $row)
                        <tr>
                            <td>{{ $row['department'] }}</td>
                            <td><span class="badge sev-{{ $row['severity'] }}">{{ $row['severity'] }}</span></td>
                            <td>{{ $row['0-30'] }}</td>
                            <td>{{ $row['31-60'] }}</td>
                            <td>{{ $row['61-90'] }}</td>
                            <td>{{ $row['90+'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @elseif ($section['type'] === 'board_extract')
            <h2>{{ $section['title'] }}</h2>
            <p style="font-size: 9px; color: #718096;">
                Every exception escalated to the matrix, unanswered past its SLA, or re-issued more than once.
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Ref</th><th>Exception</th><th>Department</th><th>Severity</th>
                        <th>Round</th><th>Response due</th><th>Matrix escalated</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($boardExtract as $escalation)
                        <tr>
                            <td class="mono">{{ $escalation->reference }}</td>
                            <td>{{ Str::limit($escalation->exception?->title, 50) }}</td>
                            <td>{{ $escalation->targetLabel() }}</td>
                            <td><span class="badge sev-{{ $escalation->severity }}">{{ $escalation->severity }}</span></td>
                            <td>{{ $escalation->round_no }}</td>
                            <td>{{ $escalation->response_due_at?->format('d M Y') }}</td>
                            <td>{{ $escalation->matrix_escalated_at?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $escalation->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Nothing requires board attention.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endforeach
@endsection
