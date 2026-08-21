<?php

namespace App\Http\Controllers;

use App\Http\Requests\WhistleblowingReportRequest;
use App\Models\Tenant;
use App\Services\CaseService;
use App\Services\SpeakUpMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The whistleblowing intake and reporter follow-up channel (11.4 + CR).
 *
 * Reachable without authentication on purpose: requiring a login to report
 * wrongdoing defeats the control.
 *
 * Two routes through the same form:
 *
 *   - **Confidential** (CR): technical metadata is captured, disclosed by a
 *     non-dismissible notice the reporter must acknowledge before
 *     submitting. The channel is labelled confidential — never anonymous —
 *     wherever this applies, because a channel that logs an IP is not
 *     anonymous and must not claim to be.
 *   - **Anonymous** (where the tenant keeps the route enabled): nothing
 *     that identifies the reporter is stored — no metadata row, no user
 *     id, and the audit trail carries no IP or agent. Unchanged from 11.4.
 */
class WhistleblowingController extends Controller
{
    public function __construct(
        private CaseService $cases,
        private SpeakUpMetadataService $metadata,
    ) {}

    public function create(Request $request): Response
    {
        $settings = $this->metadata->settings($request->user()?->tenant_id ?? $this->defaultTenantId());

        // Server-issued form-open timestamp: time-on-form is computed from
        // this on submission, never from a client-declared figure.
        $request->session()->put('speak_up_form_issued_at', now()->timestamp);

        return Inertia::render('Whistleblowing/Report', [
            'concernTypes' => [
                'fraud', 'bribery or corruption', 'financial misreporting',
                'regulatory breach', 'conflict of interest', 'harassment or bullying',
                'health and safety', 'data misuse', 'other',
            ],
            'metadataCapture' => $settings['metadata_capture'],
            'anonymousMode' => $settings['anonymous_mode'],
            'noticeVersion' => $settings['notice_version'],
            'noticeRich' => $settings['notice_rich'],
            'noticeText' => $settings['notice_text'],
            'retentionMonths' => $settings['retention_months'],
        ]);
    }

    public function store(WhistleblowingReportRequest $request): RedirectResponse
    {
        $tenantId = $request->user()?->tenant_id ?? $this->defaultTenantId();

        abort_if($tenantId === null, 503, 'Reporting is not configured on this installation.');

        $validated = $request->validated();
        $settings = $this->metadata->settings($tenantId);

        $anonymous = $this->resolveAnonymous($request, $settings);

        // The disclosed route is only lawful because it is disclosed: no
        // acknowledgement, no submission (CR §5, NDPA).
        if (! $anonymous && $settings['metadata_capture'] && ! $request->boolean('notice_acknowledged')) {
            throw ValidationException::withMessages([
                'notice_acknowledged' => 'Please read and acknowledge the data collection notice to submit.',
            ]);
        }

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

        // Metadata only ever attaches to the confidential route; the
        // service refuses an anonymous case even if this call slipped.
        if (! $anonymous && $settings['metadata_capture']) {
            $this->metadata->capture(
                $result['case'],
                $request,
                $validated['client_meta'] ?? [],
                $this->sessionDuration($request),
                $settings['notice_version'],
            );
        }

        return redirect()
            ->route('whistleblowing.submitted')
            ->with('reporterToken', $result['token'])
            ->with('caseRef', $result['case']->case_ref)
            ->with('success', 'Your report has been received.');
    }

    /**
     * Which route was chosen — honouring the tenant's configuration. When
     * the anonymous route is disabled every submission is confidential;
     * when metadata capture is off entirely the module behaves exactly as
     * it did before the CR (anonymous by default).
     */
    private function resolveAnonymous(WhistleblowingReportRequest $request, array $settings): bool
    {
        if (! $settings['metadata_capture']) {
            return $request->boolean('anonymous', true);
        }

        $mode = $request->input('mode')
            ?? ($request->boolean('anonymous', false) ? 'anonymous' : 'confidential');

        if ($mode === 'anonymous' && ! $settings['anonymous_mode']) {
            throw ValidationException::withMessages([
                'mode' => 'Anonymous reporting is not enabled on this installation.',
            ]);
        }

        return $mode === 'anonymous';
    }

    /** Server-side time-on-form; null when the open timestamp is absent. */
    private function sessionDuration(Request $request): ?int
    {
        $issuedAt = $request->session()->pull('speak_up_form_issued_at');

        return is_numeric($issuedAt) ? max(0, now()->timestamp - (int) $issuedAt) : null;
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
