<?php

namespace App\Http\Controllers;

use App\Http\Requests\RiskAppetiteRequest;
use App\Models\OrganisationUnit;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Services\AppetiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiskAppetiteController extends Controller
{
    public function __construct(private AppetiteService $appetiteService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RiskAppetite::class);

        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Risks/Appetite', [
            'appetites' => RiskAppetite::query()
                ->with(['category:id,name', 'entity:id,name', 'approver:id,name'])
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('status')
                ->orderBy('risk_category_id')
                ->paginate(20)
                ->withQueryString(),
            'positions' => $this->appetiteService->positions($tenantId),
            'uncovered' => $this->appetiteService->categoriesWithoutAppetite($tenantId),
            'filters' => $request->only(['status']),
            'statuses' => RiskAppetite::STATUSES,
            'levels' => RiskAppetite::LEVELS,
            'riskCategories' => RiskCategory::query()->orderBy('sort_order')->get(['id', 'code', 'name']),
            'entities' => OrganisationUnit::query()->orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => $request->user()->can('manage appetite'),
                'approve' => $request->user()->can('approve appetite'),
            ],
        ]);
    }

    public function store(RiskAppetiteRequest $request): RedirectResponse
    {
        $this->authorize('create', RiskAppetite::class);

        $appetite = $this->appetiteService->create($request->validated(), $request->user());

        return back()->with('success', "Appetite statement drafted (v{$appetite->version_no}).");
    }

    public function submit(RiskAppetite $appetite): RedirectResponse
    {
        $this->authorize('update', $appetite);

        $this->appetiteService->submitForApproval($appetite);

        return back()->with('success', 'Appetite statement submitted for approval.');
    }

    public function approve(Request $request, RiskAppetite $appetite): RedirectResponse
    {
        $this->authorize('approve', $appetite);

        $this->appetiteService->approve($appetite, $request->user());
        $this->appetiteService->evaluateAll($appetite->tenant_id);

        return back()->with('success', 'Appetite statement approved and now active.');
    }

    /** A change creates the next version; the approved one is never edited. */
    public function supersede(RiskAppetiteRequest $request, RiskAppetite $appetite): RedirectResponse
    {
        $this->authorize('supersede', $appetite);

        $next = $this->appetiteService->supersede($appetite, $request->validated(), $request->user());

        return back()->with('success', "Version {$next->version_no} drafted — approve it to replace the current statement.");
    }
}
