<?php

namespace App\Http\Controllers;

use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Services\ReportDesignerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportRunController extends Controller
{
    public function __construct(private ReportDesignerService $designer) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportDefinition::class);

        return Inertia::render('Reports/Runs', [
            'runs' => ReportRun::query()
                ->with(['definition:id,code,name,confidentiality', 'requester:id,name', 'schedule:id,name'])
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->report_definition_id, fn ($q, $d) => $q->where('report_definition_id', $d))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $request->only(['status', 'report_definition_id']),
            'statuses' => ReportRun::STATUSES,
            'definitions' => ReportDefinition::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, ReportDefinition $definition): RedirectResponse
    {
        $this->authorize('run', $definition);

        $validated = $request->validate([
            'output_format' => ['required', Rule::in($definition->formats())],
            'parameters' => ['nullable', 'array'],
            'parameters.period' => ['nullable', 'string', 'max:40'],
            'parameters.entity_id' => ['nullable', 'integer', 'exists:organisation_units,id'],
            'parameters.framework_id' => ['nullable', 'integer', 'exists:frameworks,id'],
        ]);

        $run = $this->designer->run(
            $definition,
            $request->user(),
            $validated['output_format'],
            $validated['parameters'] ?? [],
        );

        return back()->with(
            $run->status === 'Completed' ? 'success' : 'error',
            $run->status === 'Completed'
                ? "{$run->run_ref} generated. The download link expires in ".config('dashboards.report_link_ttl_hours').' hours.'
                : "Generation failed: {$run->error_message}",
        );
    }

    /**
     * Download. Two independent gates: the export permission, and a token
     * that matches this run. The token is what makes a mailed link usable
     * without weakening the permission for everyone else, and it expires.
     */
    public function download(Request $request, ReportRun $run): StreamedResponse
    {
        $this->authorize('viewAny', ReportDefinition::class);

        abort_unless($run->isDownloadable(), 410, 'This report is no longer available for download.');

        if ($request->filled('token')) {
            abort_unless(hash_equals((string) $run->download_token, (string) $request->query('token')), 403);
        }

        $disk = Storage::disk(config('dashboards.report_disk'));
        abort_unless($disk->exists($run->output_path), 404);

        $run->increment('download_count');
        $run->auditAction('report.downloaded', null, ['by' => $request->user()->id]);

        return $disk->download(
            $run->output_path,
            $run->run_ref.'.'.pathinfo($run->output_path, PATHINFO_EXTENSION),
        );
    }
}
