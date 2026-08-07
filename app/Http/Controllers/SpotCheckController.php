<?php

namespace App\Http\Controllers;

use App\Http\Requests\FindingRequest;
use App\Http\Requests\SpotCheckRequest;
use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\OrganisationUnit;
use App\Models\SpotCheck;
use App\Models\User;
use App\Services\ExceptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpotCheckController extends Controller
{
    public function __construct(private ExceptionService $exceptionService) {}

    public function index(Request $request): Response
    {
        $query = SpotCheck::query()
            ->with(['unit:id,name', 'process:id,name', 'conductor:id,name'])
            ->withCount('findings')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhere('title', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s));

        return Inertia::render('SpotChecks/Index', [
            'spotChecks' => $query->latest('date_conducted')->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'status']),
            'statuses' => SpotCheck::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SpotChecks/Create', [
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::orderBy('name')->get(['id', 'name']),
            'controls' => Control::active()->where('is_template', false)->orderBy('control_ref')->get(['id', 'control_ref', 'title']),
        ]);
    }

    public function store(SpotCheckRequest $request): RedirectResponse
    {
        $spotCheck = SpotCheck::create([
            ...$request->validated(),
            'reference' => SpotCheck::nextReference('SPC'),
            'conducted_by' => $request->user()->id,
            'status' => 'In Progress',
        ]);

        return redirect()->route('spot-checks.show', $spotCheck)
            ->with('success', "Spot check {$spotCheck->reference} initiated.");
    }

    public function show(SpotCheck $spotCheck): Response
    {
        $spotCheck->load(['unit:id,name', 'process:id,name', 'conductor:id,name', 'findings.control:id,control_ref,title', 'findings.responsibleParty:id,name']);

        $controls = $spotCheck->control_ids
            ? Control::whereIn('id', $spotCheck->control_ids)->get(['id', 'control_ref', 'title'])
            : collect();

        return Inertia::render('SpotChecks/Show', [
            'spotCheck' => $spotCheck,
            'scopedControls' => $controls,
            'allControls' => Control::active()->where('is_template', false)->orderBy('control_ref')->get(['id', 'control_ref', 'title']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Inline finding capture (FR-4.2). */
    public function storeFinding(FindingRequest $request, SpotCheck $spotCheck): RedirectResponse
    {
        abort_if(in_array($spotCheck->status, ['Completed', 'Report Issued'], true), 403, 'This spot check is complete.');

        $spotCheck->findings()->create($request->validated());

        return back()->with('success', 'Finding captured.');
    }

    public function updateFinding(FindingRequest $request, SpotCheck $spotCheck, int $findingId): RedirectResponse
    {
        $finding = $spotCheck->findings()->findOrFail($findingId);
        $finding->update($request->validated());

        return back()->with('success', 'Finding updated.');
    }

    /**
     * Completion pushes every finding into the exception tracker (FR-4.3);
     * management responses should be captured first (FR-4.8).
     */
    public function complete(Request $request, SpotCheck $spotCheck): RedirectResponse
    {
        abort_unless($spotCheck->status === 'In Progress', 403);

        $spotCheck->update(['status' => 'Completed']);

        $spotCheck->findings->each(fn ($finding) => $this->exceptionService->raiseFromFinding($finding));

        return back()->with('success', 'Spot check completed — findings now tracked as exceptions.');
    }

    public function issueReport(Request $request, SpotCheck $spotCheck): RedirectResponse
    {
        abort_unless($spotCheck->status === 'Completed', 403);

        $spotCheck->update(['status' => 'Report Issued', 'report_issued_at' => now()]);
        $spotCheck->auditAction('report_issued');

        return back()->with('success', 'Spot check report issued.');
    }
}
