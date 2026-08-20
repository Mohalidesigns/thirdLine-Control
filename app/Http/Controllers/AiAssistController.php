<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiAssistRequest;
use App\Http\Requests\AiDecisionRequest;
use App\Http\Requests\AiFeedbackRequest;
use App\Models\AiInteraction;
use App\Models\BusinessProcess;
use App\Models\CheckResult;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\RegulatoryChange;
use App\Models\ReportRun;
use App\Models\Risk;
use App\Models\SubmissionPack;
use App\Services\Ai\AiDraft;
use App\Services\Ai\AiService;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The Assist affordance (§C.9). Thin by construction: it authorises,
 * resolves the subject, delegates to AiService and returns.
 *
 * Responses are JSON rather than Inertia renders. An Assist button appears
 * next to the field it helps with, on a page the user is already editing —
 * a full page render would throw away their unsaved work, which is the
 * fastest way to make a helpful feature hated.
 */
class AiAssistController extends Controller
{
    public function __construct(private AiService $ai) {}

    /** Run a capability and return the draft for the review panel. */
    public function store(AiAssistRequest $request): JsonResponse
    {
        $subject = $this->resolveSubject($request);

        try {
            $draft = $this->ai->run(
                $request->string('capability')->toString(),
                $request->user(),
                $request->validated(),
                $subject,
            );
        } catch (AiException $e) {
            // userMessage() is fixed, safe text — never the provider's body,
            // which is a route by which a key reaches a screen (R9).
            return response()->json([
                'message' => $e->userMessage(),
                'status' => $e->status(),
            ], $e->status() === 'Rate Limited' ? 429 : 422);
        }

        return response()->json(['draft' => $draft->preview()]);
    }

    /** Re-read a draft — the review panel after a page refresh. */
    public function show(AiInteraction $interaction): JsonResponse
    {
        $this->authorize('view', $interaction);

        return response()->json([
            'draft' => AiDraft::fromInteraction($interaction)->preview(),
        ]);
    }

    /**
     * Accept / Edit / Reject — the human decision R4 requires.
     *
     * A redirect rather than JSON on accept, because accepting usually
     * creates a record the user should now be looking at.
     */
    public function decide(AiDecisionRequest $request, AiInteraction $interaction): RedirectResponse|JsonResponse
    {
        $this->authorize('decide', $interaction);

        try {
            if ($request->string('decision')->toString() === 'reject') {
                $this->ai->reject(
                    $interaction,
                    $request->user(),
                    $request->string('reason')->toString(),
                    $request->input('category'),
                );

                return back()->with('info', 'Suggestion rejected. Thank you — the reason feeds the next prompt version.');
            }

            $result = $this->ai->accept(
                $interaction,
                $request->user(),
                (array) $request->input('edits', []),
            );
        } catch (AiException $e) {
            return back()->with('error', $e->userMessage());
        }

        $applied = $result['applied'];

        if ($applied && $route = $this->routeFor($applied)) {
            return redirect()->to($route)->with(
                'success',
                'Suggestion accepted and created as a draft for review.',
            );
        }

        return back()->with('success', 'Suggestion accepted.');
    }

    /** Rating and comments on a draft, accepted or not. */
    public function feedback(AiFeedbackRequest $request, AiInteraction $interaction): RedirectResponse
    {
        $this->authorize('feedback', $interaction);

        $interaction->feedback()->create([
            'tenant_id' => $interaction->tenant_id,
            'user_id' => $request->user()->id,
            ...$request->validated(),
        ]);

        return back()->with('success', 'Thank you — your feedback has been recorded.');
    }

    /**
     * Resolve the subject from its source-type key rather than a class name
     * off the wire. Accepting a fully-qualified class from a request is how
     * a morph parameter becomes an arbitrary-model-read primitive; the map
     * is the allowlist.
     */
    private function resolveSubject(Request $request): ?Model
    {
        $type = $request->input('subject_type');
        $id = $request->integer('subject_id');

        if (! $type || ! $id) {
            return null;
        }

        $map = [
            ...KnowledgeIndexer::sourceMap(),
            // Subjects that are not indexed but can still be asked about.
            'check_result' => CheckResult::class,
            'business_process' => BusinessProcess::class,
            'regulatory_change' => RegulatoryChange::class,
            'report_run' => ReportRun::class,
            'submission_pack' => SubmissionPack::class,
        ];

        if (! isset($map[$type])) {
            return null;
        }

        // Tenant scoping applies through the model's own global scope, and
        // AiGateway then runs the subject through its policy.
        return $map[$type]::find($id);
    }

    private function routeFor(Model $record): ?string
    {
        $routes = [
            Control::class => 'controls.show',
            Risk::class => 'risks.show',
            ControlException::class => 'exceptions.show',
            RegulatoryChange::class => 'regulatory-changes.show',
        ];

        $name = $routes[$record::class] ?? null;

        return $name && Route::has($name)
            ? route($name, $record)
            : null;
    }
}
