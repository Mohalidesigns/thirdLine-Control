<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublicExceptionResponseRequest;
use App\Services\ExceptionResponseService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CR1.3 / R-H — the token answer screen for a respondent outside the
 * system. A token grants sight of ONE exception and the right to answer
 * it: no listing, no navigation into the app, no other tenant, ever.
 * Route-throttled, every access logged with IP and user agent.
 */
class PublicExceptionResponseController extends Controller
{
    public function __construct(private ExceptionResponseService $responseService) {}

    public function show(string $token): Response
    {
        $link = $this->responseService->resolveLink($token);
        $escalation = $link->escalation()->withoutGlobalScope('tenant')->first();

        // Opening the link IS the acknowledgement (CR1.3).
        if ($link->status === 'active') {
            $this->responseService->acknowledge($escalation);
        }

        return Inertia::render('Public/ExceptionResponse', [
            // Only what the respondent needs to answer — no evidence, no
            // internal commentary, no Restricted detail (CR1.2).
            'escalation' => [
                'reference' => $escalation->reference,
                'round_no' => $escalation->round_no,
                'severity' => $escalation->severity,
                'issue_note' => $escalation->issue_note,
                'required_response' => $escalation->required_response,
                'response_due_at' => $escalation->response_due_at,
                'status' => $escalation->status,
                'external_name' => $escalation->external_name,
                'external_organisation' => $escalation->external_organisation,
                'exception' => [
                    'reference' => $escalation->exception?->reference,
                    'title' => $escalation->exception?->title,
                ],
            ],
            'token' => $token,
            'submitted' => $link->status === 'used',
        ]);
    }

    public function submit(PublicExceptionResponseRequest $request, string $token)
    {
        $link = $this->responseService->resolveLink($token);

        abort_unless($link->isUsable(), 410, 'This response link is no longer valid.');

        $escalation = $link->escalation()->withoutGlobalScope('tenant')->first();

        $this->responseService->acknowledge($escalation);
        $this->responseService->submit($escalation, $request->validated(), null, 'secure_link');
        $this->responseService->markLinkUsed($link);

        return redirect()
            ->route('public.exception-response.show', ['token' => $token])
            ->with('success', 'Your response has been submitted on the record. You may close this page.');
    }
}
