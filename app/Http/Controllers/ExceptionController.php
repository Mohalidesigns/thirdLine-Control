<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExceptionRequest;
use App\Models\BusinessProcess;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\ExceptionEscalation;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Rules\RichTextRule;
use App\Services\ExceptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExceptionController extends Controller
{
    public function __construct(private ExceptionService $exceptionService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ControlException::class);

        $user = $request->user();

        $query = ControlException::query()
            ->with(['control:id,control_ref,title', 'owner:id,name', 'responsibleParty:id,name', 'unit:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhere('title', 'like', "%{$s}%")))
            ->when($request->status, fn ($q, $s) => $s === 'open' ? $q->open() : $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->unit_id, fn ($q, $u) => $q->where('unit_id', $u))
            // FR-10.6 (DEF-015): period, process, control owner and risk.
            ->when($request->owner_id, fn ($q, $o) => $q->where('owner_id', $o))
            ->when($request->process_id, fn ($q, $p) => $q
                ->whereHas('control', fn ($c) => $c->where('process_id', $p)))
            ->when($request->risk_id, fn ($q, $r) => $q
                ->whereHas('control.risks', fn ($w) => $w->where('risks.id', $r)))
            ->when($request->period, function ($q, $p) {
                [$year, $month] = array_pad(explode('-', (string) $p), 2, null);

                return $q->whereYear('date_raised', (int) $year)
                    ->when($month, fn ($qq) => $qq->whereMonth('date_raised', (int) $month));
            })
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue());

        if ($request->input('view') === 'mine'
            || ($user->hasRole('Control Owner') && ! $user->hasAnyRole(['Control Officer', 'Control Function Head', 'System Administrator', 'Executive Viewer', 'Line Manager']))) {
            $query->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('responsible_party_id', $user->id));
        }

        return Inertia::render('Exceptions/Index', [
            'exceptions' => $query->orderByDesc('date_raised')->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'status', 'severity', 'unit_id', 'owner_id', 'process_id', 'risk_id', 'period', 'overdue', 'view']),
            'statuses' => ControlException::STATUSES,
            'severities' => ControlException::SEVERITIES,
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ControlException::class);

        return Inertia::render('Exceptions/Create', [
            'controls' => Control::where('is_template', false)->orderBy('control_ref')->get(['id', 'control_ref', 'title', 'owner_id', 'unit_id']),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'severities' => ControlException::SEVERITIES,
        ]);
    }

    public function store(ExceptionRequest $request): RedirectResponse
    {
        $this->authorize('create', ControlException::class);

        $exception = ControlException::create([
            ...$request->validated(),
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Manual',
            'raised_by' => $request->user()->id,
            'date_raised' => now()->toDateString(),
            'status' => $request->input('owner_id') ? 'Assigned' : 'Open',
        ]);

        // CR2-A: a manual raise on a shared control also tells its
        // co-owner units.
        $this->exceptionService->notifyRaised($exception);

        return redirect()->route('exceptions.show', $exception)
            ->with('success', "Exception {$exception->reference} raised.");
    }

    public function show(ControlException $exception): Response
    {
        $this->authorize('view', $exception);

        $exception->load([
            'control:id,control_ref,title,owner_id', 'risk:id,code,title', 'owner:id,name',
            'responsibleParty:id,name', 'unit:id,name', 'raisedBy:id,name', 'verifier:id,name',
            'activities.actor:id,name', 'compensatingControls.owner:id,name',
            'escalationEvents.recipient:id,name', 'recurrenceOf:id,reference',
            // CR-01: the departmental escalation loop.
            'escalations.unit:id,name', 'escalations.process:id,name',
            'escalations.respondent:id,name', 'escalations.issuer:id,name',
        ]);

        $user = auth()->user();

        return Inertia::render('Exceptions/Show', [
            'exception' => $exception,
            'evidence' => Evidence::query()
                ->where('linked_type', ControlException::class)
                ->where('linked_id', $exception->id)
                ->with('uploader:id,name')
                ->latest('uploaded_at')
                ->get(),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::orderBy('name')->get(['id', 'name']),
            'can' => [
                'update' => $user->can('update', $exception),
                'escalate' => $user->can('issue', ExceptionEscalation::class),
                'remediate' => $user->can('remediate', $exception),
                'close' => $user->can('close', $exception),
                'requestExtension' => $user->can('requestExtension', $exception),
                'decideExtension' => $user->can('decideExtension', $exception),
                'acceptRisk' => $user->can('acceptRisk', $exception),
                'comment' => $user->can('comment', $exception),
            ],
        ]);
    }

    public function assign(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('update', $exception);

        $request->validate(['user_id' => ['required', 'tenant_user']]);

        $this->exceptionService->assign($exception, User::findOrFail($request->integer('user_id')), $request->user());

        return back()->with('success', 'Exception assigned.');
    }

    public function markInProgress(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('remediate', $exception);

        $validated = $request->validate([
            'remediation_plan' => ['nullable', 'string', 'max:10000'],
            'remediation_plan_rich' => ['nullable', 'array', new RichTextRule],
        ]);

        $this->exceptionService->markInProgress(
            $exception,
            $validated['remediation_plan'] ?? null,
            $validated['remediation_plan_rich'] ?? null,
        );

        return back()->with('success', 'Remediation in progress.');
    }

    public function markRemediated(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('remediate', $exception);

        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->exceptionService->markRemediated($exception, $request->user(), $request->string('note'));

        return back()->with('success', 'Marked remediated — pending control function verification.');
    }

    public function close(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('close', $exception);

        $verification = $request->validate([
            'verification_method' => ['required', 'string', 'max:500'],
            'closure_notes' => ['nullable', 'string', 'max:2000'],
            'closure_notes_rich' => ['nullable', 'array', new RichTextRule],
        ]);

        $this->exceptionService->verifyAndClose($exception, $request->user(), $verification);

        return back()->with('success', "Exception {$exception->reference} verified and closed.");
    }

    public function failVerification(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('close', $exception);

        $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->exceptionService->failVerification($exception, $request->user(), $request->string('reason'));

        return back()->with('warning', 'Verification failed — exception returned to In Progress and escalation resumes.');
    }

    public function requestExtension(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('requestExtension', $exception);

        $validated = $request->validate([
            'new_date' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->exceptionService->requestExtension($exception, $request->user(), $validated['new_date'], $validated['reason']);

        return back()->with('success', 'Extension requested — awaiting control function decision.');
    }

    public function decideExtension(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('decideExtension', $exception);

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'new_date' => ['required_if:approved,true', 'nullable', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->exceptionService->decideExtension(
            $exception,
            $request->user(),
            $validated['approved'],
            $validated['new_date'] ?? '',
            $validated['reason'],
        );

        return back()->with('success', 'Extension decision recorded.');
    }

    public function acceptRisk(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('acceptRisk', $exception);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'reason_rich' => ['nullable', 'array', new RichTextRule],
            'expiry_date' => ['required', 'date', 'after:today'],
        ]);

        $this->exceptionService->acceptRisk(
            $exception,
            $request->user(),
            $validated['reason'],
            $validated['expiry_date'],
            $validated['reason_rich'] ?? null,
        );

        return back()->with('success', 'Risk formally accepted with expiry.');
    }

    public function comment(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('comment', $exception);

        $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $exception->logActivity('Comment', null, null, $request->string('note'));

        return back();
    }
}
