<?php

namespace App\Http\Controllers;

use App\Http\Requests\SoaEntryRequest;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Services\SoaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SoaController extends Controller
{
    public function __construct(private SoaService $soa) {}

    public function show(Request $request, Framework $framework): Response
    {
        abort_unless($request->user()->can('view frameworks'), 403);

        return Inertia::render('Frameworks/Soa', [
            'framework' => $framework->only(['id', 'code', 'name', 'version', 'is_certifiable']),
            'statement' => $this->soa->statement($framework),
            'can' => ['decide' => $request->user()->can('manage soa')],
        ]);
    }

    public function decide(SoaEntryRequest $request, Framework $framework): RedirectResponse
    {
        $data = $request->validated();

        $requirement = FrameworkRequirement::where('framework_id', $framework->id)
            ->findOrFail($data['requirement_id']);

        $this->soa->decide($framework, $requirement, collect($data)->except('requirement_id')->all(), $request->user());

        return back()->with('success', 'Applicability decision recorded.');
    }

    public function export(Request $request, Framework $framework)
    {
        abort_unless($request->user()->can('view frameworks'), 403);

        return $this->soa->renderPdf($framework)
            ->download('soa-'.$framework->code.'.pdf');
    }
}
