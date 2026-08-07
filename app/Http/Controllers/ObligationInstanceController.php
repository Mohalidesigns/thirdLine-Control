<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObligationSubmissionRequest;
use App\Models\ObligationInstance;
use App\Services\ObligationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ObligationInstanceController extends Controller
{
    public function __construct(private ObligationService $obligations) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ObligationInstance::class);

        $query = ObligationInstance::query()
            ->with([
                'obligation:id,obligation_ref,title,regulator_id,obligation_type,verification_status',
                'obligation.regulator:id,code,name',
                'assignment.entity:id,name',
                'assignment.owner:id,name',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->whereHas('assignment', fn ($w) => $w->where('owner_id', $request->user()->id)))
            ->when($request->entity_id, fn ($q, $e) => $q->whereHas('assignment', fn ($w) => $w->where('entity_id', $e)))
            ->when($request->search, fn ($q, $s) => $q->whereHas('obligation', fn ($w) => $w
                ->where('title', 'like', "%{$s}%")->orWhere('obligation_ref', 'like', "%{$s}%")));

        return Inertia::render('Obligations/Instances', [
            'instances' => $query->orderBy('due_at')->paginate(20)->withQueryString(),
            'filters' => $request->only(['status', 'mine', 'entity_id', 'search']),
            'statuses' => ObligationInstance::STATUSES,
            'exposure' => $this->obligations->exposureByCurrency(),
        ]);
    }

    public function show(Request $request, ObligationInstance $instance): Response
    {
        $this->authorize('view', $instance);

        $instance->load([
            'obligation.regulator',
            'obligation.requirement:id,ref_code,title,framework_id',
            'assignment.entity:id,name',
            'assignment.owner:id,name',
            'assignment.reviewer:id,name',
            'submitter:id,name',
            'reviewer:id,name',
            'evidence',
        ]);

        return Inertia::render('Obligations/InstanceShow', [
            'instance' => $instance,
            'can' => [
                'update' => $request->user()->can('update', $instance),
                'submit' => $request->user()->can('submit', $instance),
                'review' => $request->user()->can('review', $instance),
                'waive' => $request->user()->can('waive', $instance),
            ],
        ]);
    }

    public function start(ObligationInstance $instance): RedirectResponse
    {
        $this->authorize('update', $instance);

        $this->obligations->start($instance);

        return back()->with('success', 'Marked in progress.');
    }

    public function submit(ObligationSubmissionRequest $request, ObligationInstance $instance): RedirectResponse
    {
        $this->authorize('submit', $instance);

        $this->obligations->submit($instance, $request->user(), $request->validated());

        return back()->with('success', 'Submission recorded — awaiting review.');
    }

    public function review(Request $request, ObligationInstance $instance): RedirectResponse
    {
        $this->authorize('review', $instance);

        $data = $request->validate([
            'decision' => ['required', 'in:Accepted,Rejected'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->obligations->review($instance, $request->user(), $data['decision'], $data['notes'] ?? null);

        return back()->with('success', "Submission {$data['decision']}.");
    }

    public function waive(Request $request, ObligationInstance $instance): RedirectResponse
    {
        $this->authorize('waive', $instance);

        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:2000']]);

        $this->obligations->waive($instance, $request->user(), $data['reason']);

        return back()->with('warning', 'Obligation instance waived.');
    }
}
