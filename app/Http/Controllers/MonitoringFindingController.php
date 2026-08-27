<?php

namespace App\Http\Controllers;

use App\Models\MonitoringFinding;
use App\Models\MonitoringRule;
use App\Rules\RichTextRule;
use App\Services\MonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringFindingController extends Controller
{
    public function __construct(private MonitoringService $monitoring) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MonitoringFinding::class);

        $findings = MonitoringFinding::query()
            ->with([
                'run:id,rule_id,run_ref,started_at',
                'run.rule:id,rule_ref,name,control_id',
                'run.rule.control:id,control_ref,title',
                'reviewer:id,name',
                'exception:id,reference,status',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->rule_id, fn ($q, $r) => $q->whereHas('run', fn ($w) => $w->where('rule_id', $r)))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('record_identifier', 'like', "%{$s}%")
                ->orWhere('failure_reason', 'like', "%{$s}%")))
            ->when(! $request->filled('status'), fn ($q) => $q->open())
            ->orderByRaw("case severity when 'Critical' then 1 when 'High' then 2 when 'Medium' then 3 else 4 end")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Monitoring/Findings/Index', [
            'findings' => $findings,
            'filters' => $request->only(['status', 'severity', 'rule_id', 'search']),
            'statuses' => MonitoringFinding::STATUSES,
            'severities' => ['Critical', 'High', 'Medium', 'Low'],
            'rules' => MonitoringRule::query()->orderBy('rule_ref')->get(['id', 'rule_ref', 'name']),
            'stats' => [
                'open' => MonitoringFinding::open()->count(),
                'critical' => MonitoringFinding::open()->where('severity', 'Critical')->count(),
                'confirmed' => MonitoringFinding::where('status', 'Confirmed')->count(),
                'false_positives' => MonitoringFinding::where('status', 'False Positive')->count(),
            ],
            'can' => [
                'review' => $request->user()->can('review monitoring-findings'),
            ],
        ]);
    }

    /**
     * The review decision. Confirming raises an exception on the control
     * through ExceptionService; a false positive feeds rule tuning, and
     * both need a written reason (12.4).
     */
    public function review(Request $request, MonitoringFinding $finding): RedirectResponse
    {
        $this->authorize('review', $finding);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['Under Review', 'Confirmed', 'False Positive', 'Remediated', 'Accepted'])],
            'review_notes' => ['nullable', 'string', 'max:4000'],
            'review_notes_rich' => ['nullable', 'array', new RichTextRule],
        ]);

        $reviewed = $this->monitoring->reviewFinding(
            $finding,
            $request->user(),
            $validated['status'],
            (string) ($validated['review_notes'] ?? ''),
        );

        return back()->with('success', $reviewed->exception_id && $validated['status'] === 'Confirmed'
            ? "Finding confirmed — exception {$reviewed->exception?->reference} raised."
            : 'Finding updated.');
    }

    /** Bulk review, because a run can produce hundreds of like findings. */
    public function bulkReview(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', MonitoringFinding::class);

        $validated = $request->validate([
            'finding_ids' => ['required', 'array', 'min:1', 'max:500'],
            'finding_ids.*' => ['integer'],
            'status' => ['required', Rule::in(['Under Review', 'Confirmed', 'False Positive', 'Accepted'])],
            'review_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $findings = MonitoringFinding::query()->whereIn('id', $validated['finding_ids'])->get();
        $applied = 0;

        foreach ($findings as $finding) {
            if ($request->user()->cannot('review', $finding)) {
                continue;
            }

            $this->monitoring->reviewFinding(
                $finding,
                $request->user(),
                $validated['status'],
                (string) ($validated['review_notes'] ?? ''),
            );

            $applied++;
        }

        $skipped = $findings->count() - $applied;

        return back()->with('success', $skipped > 0
            ? "{$applied} finding(s) updated; {$skipped} skipped because they are already settled."
            : "{$applied} finding(s) updated.");
    }
}
