<?php

namespace App\Http\Controllers;

use App\Http\Requests\RiskRequest;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use App\Services\ResidualRiskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiskController extends Controller
{
    public function __construct(private ResidualRiskService $residualRiskService) {}

    public function index(Request $request): Response
    {
        $query = Risk::query()
            ->with(['owner', 'controls:id,control_ref,title,status'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c));

        return Inertia::render('Risks/Index', [
            'risks' => $query->orderBy('code')->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'category']),
            'categories' => Risk::query()->select('category')->whereNotNull('category')->distinct()->pluck('category'),
        ]);
    }

    public function store(RiskRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['inherent_rating'] = $data['inherent_likelihood'] * $data['inherent_impact'];
        $data['code'] = Risk::nextReference('RSK', false, 'code');

        $risk = Risk::create($data);
        $this->residualRiskService->recompute($risk);

        return redirect()->route('risks.show', $risk)->with('success', "Risk {$risk->code} created.");
    }

    public function show(Risk $risk): Response
    {
        $risk->load(['owner', 'controls.owner']);

        return Inertia::render('Risks/Show', [
            'risk' => $risk,
            'mappableControls' => Control::active()
                ->where('is_template', false)
                ->whereDoesntHave('risks', fn ($q) => $q->where('risks.id', $risk->id))
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(RiskRequest $request, Risk $risk): RedirectResponse
    {
        $data = $request->validated();
        $data['inherent_rating'] = $data['inherent_likelihood'] * $data['inherent_impact'];

        $risk->update($data);
        $this->residualRiskService->recompute($risk);

        return back()->with('success', "Risk {$risk->code} updated.");
    }

    /** Map a control to this risk (FR-2.2). */
    public function attachControl(Request $request, Risk $risk): RedirectResponse
    {
        $request->validate([
            'control_id' => ['required', 'exists:controls,id'],
            'contribution_weight' => ['nullable', 'numeric', 'between:0.1,10'],
        ]);

        $risk->controls()->syncWithoutDetaching([
            $request->integer('control_id') => [
                'contribution_weight' => $request->input('contribution_weight', 1),
                'mapped_by' => $request->user()->id,
                'mapped_at' => now(),
            ],
        ]);

        $this->residualRiskService->recompute($risk);

        return back()->with('success', 'Control mapped to risk.');
    }

    public function detachControl(Request $request, Risk $risk, Control $control): RedirectResponse
    {
        $risk->controls()->detach($control->id);
        $this->residualRiskService->recompute($risk);

        return back()->with('success', 'Mapping removed.');
    }

    /** Control gaps and orphan controls (FR-2.5). */
    public function gaps(): Response
    {
        return Inertia::render('Risks/Gaps', [
            'controlGaps' => Risk::controlGaps()->with('owner')->orderByDesc('inherent_rating')->get(),
            'orphanControls' => Control::orphans()
                ->where('status', '!=', 'Retired')
                ->with('owner')
                ->orderBy('control_ref')
                ->get(),
        ]);
    }
}
