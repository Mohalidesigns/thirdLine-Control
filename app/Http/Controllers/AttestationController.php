<?php

namespace App\Http\Controllers;

use App\Models\Attestation;
use App\Models\Control;
use App\Models\CsaCampaign;
use App\Models\Document;
use App\Models\Framework;
use App\Services\AttestationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttestationController extends Controller
{
    /** Attestable registry: type key → [model, label]. Extended by Phase 9.6 documents. */
    public const ATTESTABLES = [
        'document' => Document::class,
        'control' => Control::class,
        'framework' => Framework::class,
    ];

    public function __construct(private AttestationService $attestationService) {}

    /** Register of attestations + my outstanding ones. */
    public function index(Request $request)
    {
        abort_unless($request->user()->can('view attestations') || $request->user()->can('respond campaigns'), 403);

        $user = $request->user();
        $canViewAll = $user->can('view attestations');

        $attestations = Attestation::query()
            ->with(['user:id,name', 'campaign:id,reference,name', 'attestable'])
            ->when(! $canViewAll, fn ($q) => $q->where('user_id', $user->id))
            ->when($request->campaign_id, fn ($q, $c) => $q->where('campaign_id', $c))
            ->orderByDesc('attested_at')
            ->paginate(15)
            ->withQueryString();

        // Open attestation campaigns where I still owe a signature.
        $outstanding = CsaCampaign::ofType('attestation')
            ->where('status', 'Open')
            ->whereHas('responses', fn ($q) => $q
                ->where('respondent_id', $user->id)
                ->whereNotIn('status', ['Submitted', 'Accepted']))
            ->get(['id', 'reference', 'name', 'closes_at', 'scope_definition']);

        return Inertia::render('Attestations/Index', [
            'attestations' => $attestations,
            'outstanding' => $outstanding->map(function ($c) {
                $subject = $this->resolveSubject($c);

                return [
                    'id' => $c->id,
                    'reference' => $c->reference,
                    'name' => $c->name,
                    'closes_at' => $c->closes_at,
                    'subject' => $subject ? [
                        'type' => $c->scope_definition['attestable_type'] ?? null,
                        'label' => $this->subjectLabel($c),
                        'text' => $this->subjectText($c),
                    ] : null,
                ];
            })->filter(fn ($c) => $c['subject'] !== null)->values(),
            'filters' => $request->only(['campaign_id']),
            'campaigns' => $canViewAll
                ? CsaCampaign::ofType('attestation')->orderByDesc('created_at')->get(['id', 'reference', 'name'])
                : [],
        ]);
    }

    /** Sign: snapshot the exact text, timestamp, IP and method (9.5). */
    public function store(Request $request, CsaCampaign $campaign)
    {
        abort_unless($request->user()->can('respond campaigns'), 403);

        $request->validate(['accepted' => ['accepted']]);

        $subject = $this->resolveSubject($campaign);

        abort_unless($subject !== null, 422, 'This campaign has no attestable subject configured.');

        $this->attestationService->attest($campaign, $request->user(), $subject,
            $request->input('method', 'web'));

        return back()->with('success', 'Attestation recorded — thank you.');
    }

    private function resolveSubject(CsaCampaign $campaign): ?Model
    {
        return $this->attestationService->resolveSubject($campaign);
    }

    private function subjectLabel(CsaCampaign $campaign): ?string
    {
        $subject = $this->resolveSubject($campaign);

        return $subject?->getAttribute('title') ?? $subject?->getAttribute('name');
    }

    private function subjectText(CsaCampaign $campaign): ?string
    {
        $subject = $this->resolveSubject($campaign);

        if (! $subject) {
            return null;
        }

        foreach (['attestation_text', 'body', 'content', 'description', 'objective'] as $field) {
            if (is_string($subject->getAttribute($field)) && trim($subject->getAttribute($field)) !== '') {
                return $subject->getAttribute($field);
            }
        }

        return null;
    }
}
