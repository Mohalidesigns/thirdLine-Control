<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvestigationConsequenceRequest;
use App\Models\ConsequenceAction;
use App\Models\Investigation;
use App\Rules\RichTextRule;
use App\Services\ConsequenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Consequence management (CR-04 §E.2).
 *
 * The approval gate is the point of this controller: §D.4-2 says the
 * person who recommended a consequence never approves it, and the policy
 * hides the button while the service refuses the act — belt and braces,
 * because this is the decision a disciplinary appeal examines.
 */
class InvestigationConsequenceController extends Controller
{
    public function __construct(private ConsequenceService $consequences) {}

    public function store(InvestigationConsequenceRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('recommendConsequence', $investigation);

        $action = $this->consequences->recommend($investigation, $request->validated(), $request->user());

        return back()->with('success', "Consequence {$action->reference} recommended.");
    }

    public function update(Request $request, Investigation $investigation, ConsequenceAction $action): RedirectResponse
    {
        abort_unless($action->investigation_id === $investigation->id, 404);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'start', 'implement'])],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'min:5', 'max:2000'],
            'rejection_reason_rich' => ['nullable', 'array', new RichTextRule],
            'amount_recovered' => ['nullable', 'numeric', 'min:0'],
            'implementation_note' => ['nullable', 'string', 'max:5000'],
            'implementation_note_rich' => ['nullable', 'array', new RichTextRule],
            'implemented_on' => ['nullable', 'date'],
            'evidence_id' => ['nullable', 'exists:evidence,id'],
        ]);

        $user = $request->user();

        $result = match ($validated['action']) {
            'approve' => $this->approve($action, $user),
            'reject' => $this->reject($action, $user, $validated['rejection_reason']),
            'start' => $this->consequences->markInProgress($action, $this->authorizedFor($action, $user)),
            'implement' => $this->consequences->implement($action, $this->authorizedFor($action, $user), $validated),
        };

        return back()->with('success', "Consequence {$result->reference} is now {$result->status}.");
    }

    private function approve(ConsequenceAction $action, $user): ConsequenceAction
    {
        $this->authorize('approveConsequence', $action);

        return $this->consequences->approve($action, $user);
    }

    private function reject(ConsequenceAction $action, $user, string $reason): ConsequenceAction
    {
        $this->authorize('approveConsequence', $action);

        return $this->consequences->reject($action, $user, $reason);
    }

    /** Progressing an approved action needs membership, not a second approval. */
    private function authorizedFor(ConsequenceAction $action, $user)
    {
        $this->authorize('recommendConsequence', $action->investigation);

        return $user;
    }
}
