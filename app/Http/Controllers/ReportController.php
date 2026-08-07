<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\SpotCheck;
use App\Models\TestInstance;
use App\Services\ExcelExportService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private ExcelExportService $excelExportService,
    ) {}

    public function spotCheck(SpotCheck $spotCheck): Response
    {
        return $this->reportService->spotCheckReport($spotCheck);
    }

    public function exceptionRegisterPdf(Request $request): Response
    {
        return $this->reportService->exceptionRegister($request->only(['status', 'severity']));
    }

    public function testingSummaryPdf(): Response
    {
        return $this->reportService->testingSummary();
    }

    public function boardPack(Request $request): Response
    {
        $sections = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['in:dashboard,exceptions,effectiveness,compensating'],
        ])['sections'];

        return $this->reportService->boardPack($sections);
    }

    public function exceptionsXlsx(Request $request): StreamedResponse
    {
        $rows = ControlException::query()
            ->with(['control:id,control_ref', 'owner:id,name', 'unit:id,name'])
            ->when($request->status, fn ($q, $s) => $s === 'open' ? $q->open() : $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('date_raised')
            ->limit(5000)
            ->get()
            ->map(fn ($e) => [
                $e->reference, $e->title, $e->control?->control_ref, $e->source_type,
                $e->severity, $e->status, $e->owner?->name, $e->unit?->name,
                $e->date_raised?->format('Y-m-d'), $e->target_closure_date?->format('Y-m-d'),
                $e->age_days, $e->is_overdue ? 'Yes' : 'No',
            ]);

        return $this->excelExportService->stream('exceptions.xlsx', [
            'Reference', 'Title', 'Control', 'Source', 'Severity', 'Status',
            'Owner', 'Unit', 'Raised', 'Target closure', 'Age (days)', 'Overdue',
        ], $rows);
    }

    public function controlsXlsx(): StreamedResponse
    {
        $rows = Control::query()
            ->where('is_template', false)
            ->with(['category:id,name', 'process:id,name', 'unit:id,name', 'owner:id,name'])
            ->orderBy('control_ref')
            ->limit(5000)
            ->get()
            ->map(fn ($c) => [
                $c->control_ref, $c->title, $c->type, $c->nature,
                $c->category?->name, $c->process?->name, $c->unit?->name, $c->owner?->name,
                $c->frequency, $c->is_key_control ? 'Yes' : 'No', $c->status,
                $c->latestPublishedRating()?->overall_rating ?? 'Not Tested',
            ]);

        return $this->excelExportService->stream('controls.xlsx', [
            'Reference', 'Title', 'Type', 'Nature', 'Category', 'Process', 'Unit',
            'Owner', 'Frequency', 'Key control', 'Status', 'Latest rating',
        ], $rows);
    }

    public function testInstancesXlsx(): StreamedResponse
    {
        $rows = TestInstance::query()
            ->with(['control:id,control_ref,title', 'tester:id,name', 'effectivenessRating'])
            ->orderByDesc('period_start')
            ->limit(5000)
            ->get()
            ->map(fn ($t) => [
                $t->reference, $t->control?->control_ref, $t->control?->title,
                $t->period_label, $t->due_date?->format('Y-m-d'), $t->tester?->name,
                $t->status, $t->is_overdue ? 'Yes' : 'No',
                $t->effectivenessRating?->overall_rating ?? '—',
            ]);

        return $this->excelExportService->stream('test-instances.xlsx', [
            'Reference', 'Control ref', 'Control', 'Period', 'Due', 'Tester',
            'Status', 'Overdue', 'Overall rating',
        ], $rows);
    }
}
