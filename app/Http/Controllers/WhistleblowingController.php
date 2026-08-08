<?php

namespace App\Http\Controllers;

use App\Http\Requests\WhistleblowingReportRequest;
use App\Models\Tenant;
use App\Services\CaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The whistleblowing intake and reporter follow-up channel (11.4).
 *
 * Reachable without authentication on purpose: requiring a login to report
 * wrongdoing defeats the control. Whether or not a session exists, an
 * anonymous report stores nothing that identifies the reporter — the
 * request object is never consulted for identity on that path.
 */
class WhistleblowingController extends Controller
{
    public function __construct(private CaseService $cases) {}

    public function create(): Response
    {
        return Inertia::render('Whistleblowing/Report', [
            'concernTypes' => [
                'fraud', 'bribery or corruption', 'financial misreporting',
                'regulatory breach', 'conflict of interest', 'harassment or bullying',
                'health and safety', 'data misuse', 'other',
            ],
        ]);
    }

    public function store(WhistleblowingReportRequest $request): RedirectResponse
    {
        $tenantId = $request->user()?->tenant_id ?? $this->defaultTenantId();

        abort_if($tenantId === null, 503, 'Reporting is not configured on this installation.');

        $validated = $request->validated();
        $anonymous = $request->boolean('anonymous', true);

        $result = $this->cases->open([
            'case_type' => 'whistleblowing',
            'title' => $validated['title'],
            'description' => $validated['description'],
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'subject_persons' => [],
            'access_user_ids' => [],
        ], $anonymous ? null : $request->user(), $tenantId, $anonymous);

        return redirect()
            ->route('whistleblowing.submitted')
            ->with('reporterToken', $result['token'])
            ->with('caseRef', $result['case']->case_ref)
            ->with('success', 'Your report has been received.');
    }

    /**
     * The token is shown exactly once, from the flash bag. It is never
     * stored in plaintext and cannot be recovered.
     */
    public function submitted(Request $request): Response
    {
        return Inertia::render('Whistleblowing/Submitted', [
            'token' => $request->session()->get('reporterToken'),
            'caseRef' => $request->session()->get('caseRef'),
        ]);
    }

    public function status(Request $request): Response
    {
        return Inertia::render('Whistleblowing/Status', [
            'result' => $request->session()->get('statusResult'),
            'notFound' => $request->session()->get('statusNotFound', false),
        ]);
    }

    public function checkStatus(Request $request): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:64']]);

        $status = $this->cases->statusForToken($validated['token']);

        return back()->with($status
            ? ['statusResult' => $status]
            : ['statusNotFound' => true]);
    }

    public function reply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $note = $this->cases->replyWithToken($validated['token'], $validated['message']);

        return $note
            ? back()->with('success', 'Your message has been added to the case.')
            : back()->with('statusNotFound', true);
    }

    /**
     * Branch-per-client deployment: one active tenant per installation, so
     * an unauthenticated report has an unambiguous home.
     */
    private function defaultTenantId(): ?int
    {
        return Tenant::where('status', 'active')->orderBy('id')->value('id');
    }
}
