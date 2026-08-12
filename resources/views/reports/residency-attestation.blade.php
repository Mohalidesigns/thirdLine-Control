@extends('reports.layout')

@section('title', 'Data Residency Attestation')

@section('content')
    <h2>Declaration</h2>
    <p>
        <strong>{{ $attestation->content['tenant'] }}</strong> declares that the data categories below are stored and
        processed in <strong>{{ $attestation->declared_country }}</strong> for the period
        {{ $attestation->period_start->format('d M Y') }} — {{ $attestation->period_end->format('d M Y') }}.
    </p>

    <div class="stat-row">
        <div class="stat"><div class="value">{{ $attestation->declared_country }}</div><div class="label">Declared country</div></div>
        <div class="stat"><div class="value">{{ count($attestation->content['enforcement']['layers']) }}</div><div class="label">Guarded layers</div></div>
        <div class="stat"><div class="value">{{ $attestation->content['cross_border_transfers']['count'] }}</div><div class="label">Cross-border transfers</div></div>
        <div class="stat"><div class="value mono" style="font-size: 9px;">{{ substr($attestation->checksum, 0, 16) }}…</div><div class="label">SHA-256</div></div>
    </div>

    <h2>Data categories</h2>
    <table>
        <thead>
            <tr><th>Category</th><th>Stored in</th><th>Region</th><th>Infrastructure evidence</th></tr>
        </thead>
        <tbody>
            @foreach ($attestation->content['data_categories'] as $category)
                <tr>
                    <td>{{ $category['category'] }}</td>
                    <td class="mono">{{ $category['stored_in'] }}</td>
                    <td>{{ $category['region'] }}</td>
                    <td>{{ $category['evidence'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Enforcement</h2>
    <table>
        <tbody>
            <tr><td>Guarded layers</td><td>{{ implode(', ', $attestation->content['enforcement']['layers']) }}</td></tr>
            <tr><td>Runtime disable</td><td>{{ $attestation->content['enforcement']['runtime_disable'] }}</td></tr>
            <tr><td>Declaration changes</td><td>{{ $attestation->content['enforcement']['declaration_changes'] }}</td></tr>
        </tbody>
    </table>

    <h2>Cross-border transfers in period</h2>
    @if ($attestation->content['cross_border_transfers']['count'] === 0)
        <p>No cross-border transfers were recorded in the period.</p>
    @else
        <table>
            <thead>
                <tr><th>Ref</th><th>Destination</th><th>Recipient</th><th>Lawful basis</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($attestation->content['cross_border_transfers']['items'] as $transfer)
                    <tr>
                        <td class="mono">{{ $transfer['reference'] }}</td>
                        <td>{{ $transfer['destination_country'] }}</td>
                        <td>{{ $transfer['recipient'] }}</td>
                        <td class="mono">{{ $transfer['lawful_basis'] ?? '—' }}</td>
                        <td>{{ $transfer['status'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Signature</h2>
    <p class="mono" style="font-size: 8px;">
        Reference {{ $attestation->reference }} · SHA-256 {{ $attestation->checksum }}<br>
        HMAC {{ $attestation->signature }}<br>
        Generated {{ $attestation->generated_at->format('d M Y H:i') }} by {{ $attestation->generator?->name }}
    </p>
@endsection
