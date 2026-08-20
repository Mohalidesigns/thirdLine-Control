{{-- The table view every chart section carries: the WCAG-clean equivalent
     of the figure above it, and the only reading that survives a greyscale
     printout. Never omitted, however small the chart. --}}
@if (count($table['columns'] ?? []))
    <table class="data">
        <thead>
        <tr>
            @foreach ($table['columns'] as $column)
                <th>{{ $column }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @forelse (array_slice($table['rows'] ?? [], 0, 400) as $row)
            <tr>
                @foreach ($row as $cell)
                    <td>{{ is_scalar($cell) || $cell === null ? ($cell ?? '—') : json_encode($cell) }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($table['columns']) }}">Nothing to report for this period.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    @if (count($table['rows'] ?? []) > 400)
        <p class="note">Showing the first 400 of {{ count($table['rows']) }} rows. Export the workbook for the full set.</p>
    @endif
@endif
