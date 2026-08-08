<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComplaintRequest;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Control;
use App\Models\Incident;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\ComplaintService;
use App\Services\ExcelExportService;
use App\Services\LinkageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplaintController extends Controller
{
    public function __construct(
        private ComplaintService $complaints,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Complaint::class);

        $query = Complaint::query()
            ->with(['category:id,name', 'assignee:id,name', 'entity:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->assigned_to, fn ($q, $a) => $q->where('assigned_to', $a))
            ->when($request->boolean('breached_only'), fn ($q) => $q->breached())
            ->when($request->boolean('unacknowledged_only'), fn ($q) => $q->unacknowledged())
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('subject', 'like', "%{$s}%")
                ->orWhere('complaint_ref', 'like', "%{$s}%")
                ->orWhere('tracking_id', 'like', "%{$s}%")));

        return Inertia::render('Complaints/Index', [
            'complaints' => $query->orderByDesc('received_at')->paginate(15)->withQueryString(),
            'filters' => $request->only([
                'status', 'channel', 'category_id', 'assigned_to', 'breached_only', 'unacknowledged_only', 'search',
            ]),
            'statuses' => Complaint::STATUSES,
            'channels' => Complaint::CHANNELS,
            'categories' => ComplaintCategory::ofKind('category')->active()->orderBy('sort_order')->get(['id', 'name']),
            'handlers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'open' => Complaint::open()->count(),
                'unacknowledged' => Complaint::unacknowledged()->count(),
                'breached' => Complaint::breached()->count(),
                'escalated' => Complaint::where('escalated_to_regulator', true)->count(),
            ],
            'exposure' => $this->complaints->exposureByCurrency(),
        ]);
    }

    public function store(ComplaintRequest $request): RedirectResponse
    {
        $this->authorize('create', Complaint::class);

        $complaint = $this->complaints->intake($request->validated(), $request->user());

        return redirect()
            ->route('complaints.show', $complaint)
            ->with('success', "Complaint {$complaint->complaint_ref} logged — tracking ID {$complaint->tracking_id}.");
    }

    public function show(Request $request, Complaint $complaint): Response
    {
        $this->authorize('view', $complaint);

        $this->complaints->refreshSla($complaint);

        $complaint->load([
            'category:id,name', 'rootCause:id,name', 'assignee:id,name', 'entity:id,name',
            'incident:id,incident_ref,title', 'control:id,control_ref,title',
            'activities.actor:id,name',
        ]);

        $deadline = $this->complaints->acknowledgementDeadline($complaint);

        return Inertia::render('Complaints/Show', [
            'complaint' => $complaint->fresh([
                'category:id,name', 'rootCause:id,name', 'assignee:id,name', 'entity:id,name',
                'incident:id,incident_ref,title', 'control:id,control_ref,title', 'activities.actor:id,name',
            ]),
            'links' => $this->linkage->neighbours('complaint', $complaint->id),
            // The countdown is rendered from the obligation record so the
            // screen can name the source of the deadline, not just the time.
            'acknowledgementObligation' => $deadline['obligation']?->only([
                'id', 'obligation_ref', 'title', 'legal_reference', 'verification_status', 'penalty_description',
            ]),
            'resolutionDays' => $this->complaints->resolutionDays($complaint->tenant_id),
            'rootCauses' => ComplaintCategory::ofKind('root_cause')->active()->orderBy('sort_order')->get(['id', 'name']),
            'handlers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'controls' => Control::where('status', 'Active')->orderBy('control_ref')->get(['id', 'control_ref', 'title']),
            'incidents' => Incident::open()->orderByDesc('id')->limit(50)->get(['id', 'incident_ref', 'title']),
            'can' => [
                'update' => $request->user()->can('update', $complaint),
                'acknowledge' => $request->user()->can('acknowledge', $complaint),
                'resolve' => $request->user()->can('resolve', $complaint),
                'close' => $request->user()->can('close', $complaint),
                'escalate' => $request->user()->can('escalate', $complaint),
            ],
        ]);
    }

    public function update(ComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('update', $complaint);

        $complaint->update($request->validated());
        $this->complaints->applyDeadlines($complaint->fresh());

        return back()->with('success', 'Complaint updated.');
    }

    /**
     * Acknowledgement takes no timestamp from the request by design — the
     * service stamps `now()` server-side (11.3 business rule).
     */
    public function acknowledge(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('acknowledge', $complaint);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->complaints->acknowledge($complaint, $request->user(), $validated['note'] ?? null);

        return back()->with('success', 'Acknowledgement recorded.');
    }

    public function assign(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('update', $complaint);

        $validated = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);

        $this->complaints->assign($complaint, User::findOrFail($validated['assigned_to']), $request->user());

        return back()->with('success', 'Complaint assigned.');
    }

    public function progress(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('update', $complaint);

        $validated = $request->validate([
            'action' => ['required', 'in:investigate,await_customer'],
            'note' => ['required_if:action,await_customer', 'nullable', 'string', 'max:2000'],
        ]);

        $validated['action'] === 'investigate'
            ? $this->complaints->investigate($complaint, $request->user(), $validated['note'] ?? null)
            : $this->complaints->awaitCustomer($complaint, $validated['note']);

        return back()->with('success', 'Complaint updated.');
    }

    public function resolve(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('resolve', $complaint);

        $validated = $request->validate([
            'resolution_summary' => ['required', 'string', 'max:4000'],
            'resolution_type' => ['required', 'in:'.implode(',', Complaint::RESOLUTION_TYPES)],
            'root_cause_id' => ['required', 'exists:complaint_categories,id'],
            'redress_amount_minor' => ['nullable', 'integer', 'min:0'],
            'redress_currency' => ['nullable', 'string', 'size:3', 'required_with:redress_amount_minor'],
            'customer_satisfied' => ['nullable', 'boolean'],
            'linked_control_id' => ['nullable', 'exists:controls,id'],
        ]);

        $this->complaints->resolve($complaint, $request->user(), $validated);

        return back()->with('success', 'Complaint resolved.');
    }

    public function close(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('close', $complaint);

        $this->complaints->close($complaint, $request->user());

        return back()->with('success', 'Complaint closed.');
    }

    public function reopen(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('close', $complaint);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->complaints->reopen($complaint, $request->user(), $validated['reason']);

        return back()->with('success', 'Complaint reopened.');
    }

    public function escalate(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('escalate', $complaint);

        $validated = $request->validate([
            'regulator_reference' => ['required', 'string', 'max:255'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $this->complaints->escalateToRegulator(
            $complaint,
            $request->user(),
            $validated['regulator_reference'],
            $validated['note'],
        );

        return back()->with('success', 'Complaint recorded as escalated to the regulator.');
    }

    public function linkIncident(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->authorize('update', $complaint);

        $validated = $request->validate(['incident_id' => ['required', 'exists:incidents,id']]);

        $this->complaints->linkIncident($complaint, Incident::findOrFail($validated['incident_id']));

        return back()->with('success', 'Complaint linked to incident.');
    }

    /** Complaints by root cause, back to the failing control (11.3). */
    public function analysis(Request $request): Response
    {
        $this->authorize('viewAny', Complaint::class);

        return Inertia::render('Complaints/Analysis', [
            'analysis' => $this->complaints->rootCauseAnalysis($request->only(['from', 'to', 'entity_id'])),
            'filters' => $request->only(['from', 'to', 'entity_id']),
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'exposure' => $this->complaints->exposureByCurrency(),
        ]);
    }

    /** CBN Consumer Protection Department return (11.3). */
    public function cpdReturn(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $this->authorize('export', Complaint::class);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $return = $this->complaints->cpdReturn($validated['from'], $validated['to']);

        $rows = collect($return['by_category'])->map(fn (array $row) => [
            $row['category'],
            $row['regulatory_code'] ?? '',
            $row['received'],
            $row['resolved'],
            $row['upheld'],
        ])->all();

        return $excel->stream(
            'cbn-cpd-return-'.$validated['from'].'-to-'.$validated['to'].'.xlsx',
            ['Category', 'Regulatory code', 'Received', 'Resolved', 'Upheld'],
            [
                ...$rows,
                [],
                ['Received', '', $return['received']],
                ['Acknowledged within SLA', '', $return['acknowledged_within_sla']],
                ['Resolved', '', $return['resolved']],
                ['Outstanding', '', $return['outstanding']],
                ['Escalated to regulator', '', $return['escalated_to_regulator']],
            ],
        );
    }
}
