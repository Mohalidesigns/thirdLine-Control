<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use App\Services\InvestigationDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The investigation dashboard (CR-04 §E.4).
 *
 * The Executive Viewer reaches this page and nothing else in the module:
 * aggregates, never a case file. Every figure is computed over the
 * viewer's own visibility, so the board tier sees the shape of the
 * caseload without seeing a confidential matter it is not named on.
 */
class InvestigationDashboardController extends Controller
{
    public function __construct(private InvestigationDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewDashboard', Investigation::class);

        $filters = $request->only(['period', 'from', 'to']);

        return Inertia::render('Investigations/Dashboard', [
            'data' => $this->dashboard->build($request->user(), $filters),
            'filters' => $filters,
            'can' => [
                'view_register' => $request->user()->can('view investigations'),
                'export' => $request->user()->can('export reports'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewDashboard', Investigation::class);

        abort_unless($request->user()->can('export reports'), 403);

        $rows = $this->dashboard->exportRows($request->user(), $request->only(['period', 'from', 'to']));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'investigations-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
