<?php

namespace App\Services;

use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\ReportTemplate;
use App\Models\SpotCheck;
use App\Models\TestInstance;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Renders reports from configurable templates (FR-4.5). A template is an
 * ordered set of section descriptors in sections_json — the layout is data,
 * not code. Rendering walks the sections and resolves each section's dataset.
 */
class ReportService
{
    public function __construct(private DashboardService $dashboardService) {}

    /**
     * Resolve the template to use for a report type: tenant default first,
     * then any tenant template, then a built-in fallback definition.
     */
    public function templateFor(string $reportType): array
    {
        $template = ReportTemplate::query()
            ->where('report_type', $reportType)
            ->orderByDesc('is_default')
            ->first();

        return [
            'name' => $template->name ?? $reportType,
            'sections' => $template->sections ?? $this->fallbackSections($reportType),
            'header' => $template->header_config ?? ['title' => config('app.name'), 'subtitle' => $reportType],
            'footer' => $template->footer_config ?? ['text' => 'Confidential — '.config('app.name')],
            'logo_path' => $template->logo_path ?? null,
        ];
    }

    public function spotCheckReport(SpotCheck $spotCheck): Response
    {
        $template = $this->templateFor('Spot Check');

        $spotCheck->load(['unit', 'process', 'conductor', 'findings.control', 'findings.responsibleParty']);

        return $this->renderPdf('reports.spot-check', [
            'template' => $template,
            'spotCheck' => $spotCheck,
        ], "{$spotCheck->reference}-report.pdf");
    }

    public function exceptionRegister(array $filters = []): Response
    {
        $template = $this->templateFor('Exception Register');

        $exceptions = ControlException::query()
            ->with(['control:id,control_ref,title', 'owner:id,name', 'unit:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'open' ? $q->open() : $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('date_raised')
            ->limit(500)
            ->get();

        return $this->renderPdf('reports.exception-register', [
            'template' => $template,
            'exceptions' => $exceptions,
            'filters' => $filters,
        ], 'exception-register.pdf');
    }

    public function testingSummary(): Response
    {
        $template = $this->templateFor('Test Summary');

        $instances = TestInstance::query()
            ->with(['control:id,control_ref,title', 'tester:id,name', 'effectivenessRating'])
            ->orderByDesc('period_start')
            ->limit(500)
            ->get();

        return $this->renderPdf('reports.testing-summary', [
            'template' => $template,
            'instances' => $instances,
            'metrics' => $this->dashboardService->metrics(),
        ], 'control-testing-summary.pdf');
    }

    /**
     * Board pack: selected standard reports combined into one document (FR-10.8).
     */
    public function boardPack(array $sections): Response
    {
        $template = $this->templateFor('Board Pack');

        $data = ['template' => $template, 'sections' => $sections];

        if (in_array('dashboard', $sections, true)) {
            $data['metrics'] = $this->dashboardService->metrics();
        }

        if (in_array('exceptions', $sections, true)) {
            $data['exceptions'] = ControlException::query()
                ->open()
                ->with(['control:id,control_ref,title', 'owner:id,name'])
                ->orderByRaw("field(severity, 'Critical','High','Medium','Low')")
                ->limit(300)
                ->get();
        }

        if (in_array('effectiveness', $sections, true)) {
            $data['controls'] = Control::query()
                ->active()
                ->where('is_template', false)
                ->with(['owner:id,name'])
                ->orderBy('control_ref')
                ->get()
                ->map(fn ($control) => [
                    'control' => $control,
                    'rating' => $control->latestPublishedRating()?->overall_rating ?? 'Not Tested',
                ]);
        }

        if (in_array('compensating', $sections, true)) {
            $data['compensating'] = CompensatingControl::query()
                ->whereIn('status', ['Approved', 'Active'])
                ->with(['primaryControl:id,control_ref,title', 'owner:id,name'])
                ->get();
        }

        return $this->renderPdf('reports.board-pack', $data, 'board-pack.pdf');
    }

    private function renderPdf(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }

    private function fallbackSections(string $reportType): array
    {
        return match ($reportType) {
            'Spot Check' => [
                ['type' => 'scope', 'title' => 'Scope & Coverage'],
                ['type' => 'findings', 'title' => 'Findings & Management Responses'],
                ['type' => 'sign_off', 'title' => 'Sign-off'],
            ],
            'Exception Register' => [
                ['type' => 'summary', 'title' => 'Summary'],
                ['type' => 'register', 'title' => 'Exception Register'],
            ],
            'Test Summary' => [
                ['type' => 'metrics', 'title' => 'Testing Metrics'],
                ['type' => 'results', 'title' => 'Test Results'],
            ],
            default => [['type' => 'body', 'title' => $reportType]],
        };
    }
}
