<?php

namespace App\Http\Controllers;

use App\Http\Requests\FrameworkRequirementRequest;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Services\FrameworkService;
use App\Services\MappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FrameworkRequirementController extends Controller
{
    public function __construct(
        private FrameworkService $frameworks,
        private MappingService $mappings,
    ) {}

    public function store(FrameworkRequirementRequest $request, Framework $framework): RedirectResponse
    {
        $this->authorize('update', $framework);

        $requirement = $this->frameworks->addRequirement($framework, $request->validated());

        return back()->with('success', "Requirement {$requirement->ref_code} added.");
    }

    public function update(FrameworkRequirementRequest $request, Framework $framework, FrameworkRequirement $requirement): RedirectResponse
    {
        $this->authorize('update', $framework);
        abort_unless($requirement->framework_id === $framework->id, 404);

        $this->frameworks->updateRequirement($requirement, $request->validated());

        return back()->with('success', "Requirement {$requirement->ref_code} updated.");
    }

    public function destroy(Framework $framework, FrameworkRequirement $requirement): RedirectResponse
    {
        $this->authorize('update', $framework);
        abort_unless($requirement->framework_id === $framework->id, 404);

        $requirement->delete();

        return back()->with('success', 'Requirement removed.');
    }

    /** Persist a drag-reorder of the requirement tree. */
    public function reorder(Request $request, Framework $framework): RedirectResponse
    {
        $this->authorize('update', $framework);

        $data = $request->validate([
            'ordering' => ['required', 'array'],
            'ordering.*.id' => ['required', 'integer'],
            'ordering.*.parent_id' => ['nullable', 'integer'],
            'ordering.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $this->frameworks->reorderRequirements($framework, $data['ordering']);

        return back()->with('success', 'Requirement order saved.');
    }

    /**
     * Deterministic mapping suggestions — drafts for a human to judge, with
     * the shared terms that produced the score.
     */
    public function suggestions(Request $request, Framework $framework, FrameworkRequirement $requirement): JsonResponse
    {
        $this->authorize('view', $framework);
        abort_unless($requirement->framework_id === $framework->id, 404);

        $target = $request->target_framework_id
            ? Framework::find($request->target_framework_id)
            : null;

        return response()->json([
            'requirements' => $this->mappings->suggestRequirementMappings($requirement, $target),
            'controls' => $this->mappings->suggestControlsForRequirement($requirement),
        ]);
    }
}
