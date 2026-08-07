<?php

namespace App\Http\Controllers;

use App\Models\CompensatingControl;
use App\Models\ControlException;
use App\Services\ResidualRiskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompensatingControlController extends Controller
{
    public function __construct(private ResidualRiskService $residualRiskService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CompensatingControl::class);

        $query = CompensatingControl::query()
            ->with(['primaryControl:id,control_ref,title', 'exception:id,reference', 'owner:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s));

        return Inertia::render('CompensatingControls/Index', [
            'compensatingControls' => $query->latest()->paginate(15)->withQueryString(),
            'filters' => $request->only(['status']),
            'statuses' => CompensatingControl::STATUSES,
        ]);
    }

    public function store(Request $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('create', CompensatingControl::class);

        $validated = $request->validate([
            'description' => ['required', 'string'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'is_temporary' => ['required', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required_if:is_temporary,true', 'nullable', 'date', 'after:effective_from'],
            'residual_exposure_note' => ['nullable', 'string'],
        ]);

        $exception->compensatingControls()->create([
            ...$validated,
            'tenant_id' => $exception->tenant_id,
            'primary_control_id' => $exception->control_id,
            'status' => 'Proposed',
        ]);

        $exception->logActivity('Comment', null, null, 'Compensating control proposed.');

        return back()->with('success', 'Compensating control registered — pending control function approval.');
    }

    /** Approval makes it count in residual risk (FR-6.3). */
    public function approve(Request $request, CompensatingControl $compensatingControl): RedirectResponse
    {
        $this->authorize('approve', $compensatingControl);

        $compensatingControl->update([
            'status' => 'Active',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if ($compensatingControl->primaryControl) {
            $this->residualRiskService->recomputeForControl($compensatingControl->primaryControl);
        }

        return back()->with('success', 'Compensating control approved and recognised in residual risk.');
    }

    public function withdraw(Request $request, CompensatingControl $compensatingControl): RedirectResponse
    {
        $this->authorize('update', $compensatingControl);

        $compensatingControl->update(['status' => 'Withdrawn']);

        if ($compensatingControl->primaryControl) {
            $this->residualRiskService->recomputeForControl($compensatingControl->primaryControl);
        }

        return back()->with('warning', 'Compensating control withdrawn.');
    }
}
