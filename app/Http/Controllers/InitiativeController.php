<?php

namespace App\Http\Controllers;

use App\Http\Requests\InitiativeRequest;
use App\Models\Initiative;
use App\Models\InitiativeMilestone;
use App\Services\ObjectiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InitiativeController extends Controller
{
    public function __construct(private ObjectiveService $objectives) {}

    public function store(InitiativeRequest $request): RedirectResponse
    {
        $this->authorize('create', Initiative::class);

        $data = $request->validated();
        $milestones = $data['milestones'] ?? [];
        unset($data['milestones']);

        $initiative = Initiative::create([
            ...$data,
            'reference' => Initiative::nextReference('INI'),
            'created_by' => $request->user()->id,
        ]);

        $this->syncMilestones($initiative, $milestones);
        $this->refreshObjective($initiative);

        return back()->with('success', "Initiative {$initiative->reference} created.");
    }

    public function update(InitiativeRequest $request, Initiative $initiative): RedirectResponse
    {
        $this->authorize('update', $initiative);

        $data = $request->validated();
        $milestones = $data['milestones'] ?? null;
        unset($data['milestones']);

        $initiative->update($data);

        if ($milestones !== null) {
            $this->syncMilestones($initiative, $milestones);
        }

        $this->refreshObjective($initiative->fresh());

        return back()->with('success', "Initiative {$initiative->reference} updated.");
    }

    /** Complete or reopen a milestone; the objective's roll-up follows. */
    public function completeMilestone(Request $request, Initiative $initiative, InitiativeMilestone $milestone): RedirectResponse
    {
        $this->authorize('progress', $initiative);
        abort_unless($milestone->initiative_id === $initiative->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', InitiativeMilestone::STATUSES)],
        ]);

        $complete = $validated['status'] === 'Complete';

        $milestone->update([
            'status' => $validated['status'],
            'completed_at' => $complete ? now() : null,
            'completed_by' => $complete ? $request->user()->id : null,
        ]);

        $this->objectives->initiativeProgress($initiative->fresh());
        $this->refreshObjective($initiative->fresh());

        return back()->with('success', "Milestone marked {$validated['status']}.");
    }

    /** @param array<int, array<string, mixed>> $milestones */
    private function syncMilestones(Initiative $initiative, array $milestones): void
    {
        $initiative->milestones()->delete();

        foreach ($milestones as $index => $milestone) {
            $initiative->milestones()->create([
                'tenant_id' => $initiative->tenant_id,
                'title' => $milestone['title'],
                'due_date' => $milestone['due_date'] ?? null,
                'status' => $milestone['status'] ?? 'Pending',
                'completed_at' => ($milestone['status'] ?? null) === 'Complete' ? now() : null,
                'weight' => $milestone['weight'] ?? 1,
                'sort_order' => $index,
            ]);
        }

        $this->objectives->initiativeProgress($initiative->fresh());
    }

    private function refreshObjective(Initiative $initiative): void
    {
        if ($initiative->objective) {
            $this->objectives->rollUp($initiative->objective);
        }
    }
}
