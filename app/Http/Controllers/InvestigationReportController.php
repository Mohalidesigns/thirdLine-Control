<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use App\Services\InvestigationReportBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The investigation report (CR-04 §E.3).
 *
 * Generation produces a DRAFT through the shared report pipeline, which
 * carries the run record, the checksum, the expiring download token and
 * the confidentiality-aware distribution rules with it. Regeneration is
 * blocked once a run exists — a new version is a deliberate act.
 */
class InvestigationReportController extends Controller
{
    public function __construct(private InvestigationReportBuilder $reports) {}

    public function generate(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('generateReport', $investigation);

        $validated = $request->validate([
            'format' => ['nullable', Rule::in(['pdf', 'docx'])],
        ]);

        $run = $this->reports->generate($investigation, $request->user(), $validated['format'] ?? 'pdf');

        return back()->with(
            $run->status === 'Completed' ? 'success' : 'error',
            $run->status === 'Completed'
                ? "Draft report {$run->run_ref} generated."
                : 'The report could not be generated. The investigation is unaffected — try again from the Report tab.',
        );
    }
}
