<?php

namespace App\Http\Controllers;

use App\Http\Requests\ControlRequirementMapRequest;
use App\Models\Control;
use App\Models\ControlRequirementMap;
use App\Models\FrameworkMapping;
use App\Models\FrameworkRequirement;
use App\Services\MappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ControlRequirementMapController extends Controller
{
    public function __construct(private MappingService $mappings) {}

    /** The maker-checker queue: mappings raised but not yet approved. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ControlRequirementMap::class);

        $query = ControlRequirementMap::query()
            ->with(['control:id,control_ref,title,status', 'requirement.framework:id,code,name', 'mapper:id,name', 'approver:id,name'])
            ->when($request->status === 'approved', fn ($q) => $q->approved())
            ->when($request->status === 'pending' || ! $request->status, fn ($q) => $q->pending())
            ->when($request->framework_id, fn ($q, $f) => $q->whereHas('requirement', fn ($w) => $w->where('framework_id', $f)))
            ->latest('mapped_at');

        return Inertia::render('Frameworks/Mappings', [
            'mappings' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['status', 'framework_id']),
            'stats' => [
                'pending' => ControlRequirementMap::query()->pending()->count(),
                'approved' => ControlRequirementMap::query()->approved()->count(),
            ],
            'can' => [
                'map' => $request->user()->can('map controls'),
                'approve' => $request->user()->can('approve control-mappings'),
            ],
        ]);
    }

    public function store(ControlRequirementMapRequest $request): RedirectResponse
    {
        $this->authorize('create', ControlRequirementMap::class);

        $data = $request->validated();
        $control = Control::findOrFail($data['control_id']);
        $requirement = FrameworkRequirement::findOrFail($data['requirement_id']);

        $this->mappings->mapControl($control, $requirement, $request->user(), $data);

        return back()->with('success', "{$control->control_ref} mapped to {$requirement->ref_code} — awaiting approval.");
    }

    public function approve(Request $request, ControlRequirementMap $mapping): RedirectResponse
    {
        $this->authorize('approve', $mapping);

        $this->mappings->approveMapping($mapping, $request->user());

        return back()->with('success', 'Mapping approved — it now counts toward framework coverage.');
    }

    public function reject(Request $request, ControlRequirementMap $mapping): RedirectResponse
    {
        $this->authorize('reject', $mapping);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->mappings->rejectMapping($mapping, $request->user(), $request->string('reason'));

        return back()->with('warning', 'Mapping rejected and removed.');
    }

    public function destroy(ControlRequirementMap $mapping): RedirectResponse
    {
        $this->authorize('delete', $mapping);

        $this->mappings->unmap($mapping);

        return back()->with('success', 'Mapping removed.');
    }

    /** Bulk-apply suggested control mappings as unapproved drafts. */
    public function bulkStore(Request $request, FrameworkRequirement $requirement): RedirectResponse
    {
        $this->authorize('create', ControlRequirementMap::class);

        $data = $request->validate([
            'control_ids' => ['required', 'array', 'max:50'],
            'control_ids.*' => ['integer', 'exists:controls,id'],
            'coverage' => ['nullable', 'in:full,partial,supporting'],
        ]);

        $created = $this->mappings->bulkMap(
            $requirement, $data['control_ids'], $request->user(), $data['coverage'] ?? 'supporting'
        );

        return back()->with('success', "{$created} mapping(s) created — each awaits approval.");
    }

    /**
     * One control, every requirement it satisfies, across every framework.
     * The "test once, satisfy many" proof.
     */
    public function crossFramework(Control $control): Response
    {
        $this->authorize('view', $control);

        return Inertia::render('Frameworks/CrossFramework', [
            'control' => $control->only(['id', 'control_ref', 'title', 'status', 'operating_effectiveness']),
            'rows' => $this->mappings->crossFrameworkCoverage($control),
        ]);
    }

    /** Verify a cross-framework requirement link (maker-checker). */
    public function verifyLink(Request $request, FrameworkMapping $frameworkMapping): RedirectResponse
    {
        abort_unless($request->user()->can('approve control-mappings'), 403);

        $this->mappings->verifyLink($frameworkMapping, $request->user());

        return back()->with('success', 'Cross-framework mapping verified.');
    }
}
