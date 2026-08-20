<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestScriptRequest;
use App\Models\Control;
use App\Models\TestScript;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TestScriptController extends Controller
{
    public function index(Request $request): Response
    {
        $query = TestScript::query()
            ->with(['control:id,control_ref,title', 'creator:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s));

        return Inertia::render('TestScripts/Index', [
            'scripts' => $query->latest()->paginate(15)->withQueryString(),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('TestScripts/Create', [
            'controls' => Control::where('is_template', false)->orderBy('control_ref')->get(['id', 'control_ref', 'title']),
            'preselectedControlId' => $request->integer('control_id') ?: null,
        ]);
    }

    public function store(TestScriptRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $script = DB::transaction(function () use ($data, $request) {
            $version = (int) TestScript::where('control_id', $data['control_id'])->max('version_no') + 1;

            $script = TestScript::create([
                'control_id' => $data['control_id'],
                'version_no' => $version,
                'title' => $data['title'],
                'objective' => $data['objective'] ?? null,
                'objective_rich' => $data['objective_rich'] ?? null,
                'sampling_guidance' => $data['sampling_guidance'] ?? null,
                'sampling_guidance_rich' => $data['sampling_guidance_rich'] ?? null,
                'status' => 'Pending Approval',
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['check_items'] as $i => $item) {
                $script->checkItems()->create([...$item, 'sequence' => $i + 1]);
            }

            return $script;
        });

        return redirect()->route('test-scripts.show', $script)
            ->with('success', 'Test script created and submitted for approval.');
    }

    public function show(TestScript $testScript): Response
    {
        $testScript->load(['control', 'checkItems', 'creator', 'approver']);

        return Inertia::render('TestScripts/Show', [
            'script' => $testScript,
            'canApprove' => auth()->user()->hasRole('Control Function Head')
                && $testScript->created_by !== auth()->id()
                && $testScript->status === 'Pending Approval',
        ]);
    }

    /** Scripts require approval before use (Phase 2 req. 1); maker ≠ checker. */
    public function approve(Request $request, TestScript $testScript): RedirectResponse
    {
        abort_unless(
            $request->user()->hasRole('Control Function Head')
            && $testScript->created_by !== $request->user()->id
            && $testScript->status === 'Pending Approval',
            403,
        );

        DB::transaction(function () use ($testScript, $request) {
            // Only one active script per control.
            TestScript::where('control_id', $testScript->control_id)
                ->where('status', 'Active')
                ->update(['status' => 'Retired']);

            $testScript->update([
                'status' => 'Active',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });

        return back()->with('success', 'Test script approved and active.');
    }
}
