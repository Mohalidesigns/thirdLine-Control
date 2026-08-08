<?php

namespace App\Http\Controllers;

use App\Http\Requests\CsaCampaignRequest;
use App\Models\Control;
use App\Models\CsaCampaign;
use App\Models\CsaQuestion;
use App\Models\FrameworkRequirement;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\CsaService;
use App\Services\SurveyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CsaCampaignController extends Controller
{
    public function __construct(
        private CsaService $csaService,
        private SurveyService $surveyService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', CsaCampaign::class);

        $type = in_array($request->type, CsaCampaign::TYPES, true) ? $request->type : null;

        $query = CsaCampaign::query()
            ->with(['creator:id,name', 'approver:id,name'])
            ->withCount([
                'responses',
                'responses as submitted_responses_count' => fn ($q) => $q->whereIn('status', ['Submitted', 'Under Review', 'Accepted']),
            ])
            ->when($type, fn ($q, $t) => $q->where('campaign_type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")));

        // Respondent-only users see just campaigns they hold a response in.
        $user = $request->user();
        if (! $user->can('view campaigns')) {
            $query->where(fn ($q) => $q
                ->whereHas('responses', fn ($r) => $r->where('respondent_id', $user->id))
                ->orWhere(fn ($w) => $w->where('is_anonymous', true)->where('status', 'Open')));
        }

        return Inertia::render('Csa/Index', [
            'campaigns' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'filters' => $request->only(['type', 'status', 'search']),
            'types' => CsaCampaign::TYPES,
            'statuses' => CsaCampaign::STATUSES,
            'myResponseCampaignIds' => $user->can('respond campaigns')
                ? $user->csaResponses()->pluck('campaign_id')
                : [],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', CsaCampaign::class);

        return Inertia::render('Csa/Create', [
            'defaultType' => in_array($request->type, CsaCampaign::TYPES, true) ? $request->type : 'csa',
            ...$this->formOptions(),
        ]);
    }

    public function store(CsaCampaignRequest $request)
    {
        $this->authorize('create', CsaCampaign::class);

        $campaign = $this->csaService->createCampaign($request->validated(), $request->user());

        return redirect()->route('csa-campaigns.show', $campaign)
            ->with('success', "Campaign {$campaign->reference} created as draft — build the questionnaire, then request approval.");
    }

    public function show(CsaCampaign $campaign)
    {
        $this->authorize('view', $campaign);

        $user = auth()->user();
        $questionnaire = $campaign->activeQuestionnaire();
        $canViewCampaigns = $user->can('view campaigns');

        return Inertia::render('Csa/Show', [
            'campaign' => $campaign->load(['creator:id,name', 'approver:id,name']),
            'questionnaire' => $questionnaire?->load('questions'),
            'responses' => $canViewCampaigns && ! $campaign->is_anonymous
                ? $campaign->responses()
                    ->with(['respondent:id,name', 'entity:id,name', 'control:id,control_ref,title', 'reviewer:id,name'])
                    ->orderBy('status')->get()
                : [],
            'myResponses' => $campaign->is_anonymous ? [] : $campaign->responses()
                ->where('respondent_id', $user->id)
                ->with(['entity:id,name', 'control:id,control_ref,title'])
                ->get(),
            'responseRate' => $campaign->responseRate(),
            'results' => $campaign->status !== 'Draft' && $user->can('viewResults', $campaign)
                ? $this->surveyService->results($campaign)
                : null,
            'can' => [
                'update' => $user->can('update', $campaign),
                'approve' => $user->can('approve', $campaign),
                'open' => $user->can('open', $campaign),
                'close' => $user->can('close', $campaign),
                'respondAnonymously' => $campaign->is_anonymous && $campaign->isOpen() && $user->can('respond campaigns'),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function syncQuestions(Request $request, CsaCampaign $campaign)
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.section' => ['nullable', 'string', 'max:255'],
            'questions.*.question_text' => ['required', 'string'],
            'questions.*.help_text' => ['nullable', 'string'],
            'questions.*.response_type' => ['required', Rule::in(CsaQuestion::RESPONSE_TYPES)],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.is_required' => ['boolean'],
            'questions.*.evidence_required' => ['boolean'],
            'questions.*.weight' => ['nullable', 'numeric', 'min:0'],
            'questions.*.control_id' => ['nullable', 'integer', 'exists:controls,id'],
            'questions.*.requirement_id' => ['nullable', 'integer', 'exists:framework_requirements,id'],
            'questions.*.triggers_exception_on' => ['nullable', 'array'],
            'questions.*.condition' => ['nullable', 'array'],
        ]);

        $this->csaService->syncQuestions($campaign->activeQuestionnaire(), $validated['questions']);

        return back()->with('success', 'Questionnaire saved.');
    }

    public function approve(CsaCampaign $campaign)
    {
        $this->authorize('approve', $campaign);

        $this->csaService->approveCampaign($campaign, auth()->user());

        return back()->with('success', "Campaign {$campaign->reference} approved and scheduled.");
    }

    public function open(CsaCampaign $campaign)
    {
        $this->authorize('open', $campaign);

        $this->csaService->open($campaign);

        return back()->with('success', 'Campaign opened — responses generated and respondents can now begin.');
    }

    public function close(CsaCampaign $campaign)
    {
        $this->authorize('close', $campaign);

        $this->csaService->close($campaign);

        return back()->with('success', 'Campaign closed.');
    }

    private function formOptions(): array
    {
        return [
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name', 'type']),
            'controls' => Control::where('is_template', false)->orderBy('control_ref')->get(['id', 'control_ref', 'title', 'owner_id']),
            'requirements' => FrameworkRequirement::query()->orderBy('ref_code')->limit(500)->get(['id', 'ref_code', 'title']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
