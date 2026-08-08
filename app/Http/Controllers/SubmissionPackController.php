<?php

namespace App\Http\Controllers;

use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\SubmissionPack;
use App\Services\SubmissionPackService;
use App\Submissions\SubmissionPackRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionPackController extends Controller
{
    public function __construct(
        private SubmissionPackService $submissions,
        private SubmissionPackRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SubmissionPack::class);

        $user = $request->user();

        return Inertia::render('Submissions/Index', [
            'packs' => SubmissionPack::query()
                ->with(['generator:id,name', 'reviewer:id,name', 'approver:id,name'])
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->pack_type, fn ($q, $t) => $q->where('pack_type', $t))
                ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                    ->where('pack_ref', 'like', "%{$s}%")
                    ->orWhere('period_label', 'like', "%{$s}%")))
                ->orderByDesc('id')
                ->paginate(15)
                ->withQueryString(),
            'filters' => $request->only(['status', 'pack_type', 'search']),
            'statuses' => SubmissionPack::STATUSES,
            'catalogue' => $this->registry->catalogue(),
            'entities' => OrganisationUnit::where('is_legal_entity', true)->orderBy('name')->get(['id', 'name']),
            'instances' => ObligationInstance::query()
                ->with('obligation:id,title')
                ->open()
                ->orderBy('due_at')
                ->limit(50)
                ->get(['id', 'obligation_id', 'period_label', 'due_at']),
            'threshold' => $this->submissions->threshold(),
            'can' => [
                'generate' => $user->can('generate submissions'),
                'review' => $user->can('review submissions'),
                'approve' => $user->can('approve submissions'),
                'file' => $user->can('file submissions'),
            ],
        ]);
    }

    public function show(Request $request, SubmissionPack $pack): Response
    {
        $this->authorize('view', $pack);

        $pack->load(['generator:id,name', 'reviewer:id,name', 'approver:id,name', 'submitter:id,name',
            'obligationInstance.obligation:id,title,obligation_ref', 'supersedes:id,pack_ref']);

        $user = $request->user();
        $generator = $this->registry->find($pack->pack_type);

        return Inertia::render('Submissions/Show', [
            'pack' => $pack,
            'definition' => $generator?->toArray(),
            'threshold' => $this->submissions->threshold(),
            'can' => [
                'update' => $user->can('update', $pack),
                'sendForReview' => $user->can('sendForReview', $pack),
                'review' => $user->can('review', $pack),
                'approve' => $user->can('approve', $pack),
                'file' => $user->can('file', $pack),
                'acknowledge' => $user->can('acknowledge', $pack),
                'reject' => $user->can('reject', $pack),
            ],
            // Why a button is absent, stated rather than left to guesswork —
            // "you generated this pack" is a better answer than a missing
            // control the user cannot explain.
            'blockedBecause' => array_values(array_filter([
                $pack->generated_by === $user->id && $pack->status === 'Under Review'
                    ? 'You generated this pack, so someone else must review and approve it.'
                    : null,
                $pack->reviewed_by === $user->id && $pack->status === 'Under Review'
                    ? 'You reviewed this pack, so someone else must approve it.'
                    : null,
            ])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SubmissionPack::class);

        $validated = $request->validate([
            'pack_type' => ['required', Rule::in($this->registry->types())],
            'period_label' => ['nullable', 'string', 'max:60'],
            'entity_id' => ['nullable', 'integer', 'exists:organisation_units,id'],
            'obligation_instance_id' => ['nullable', 'integer', 'exists:obligation_instances,id'],
        ]);

        $pack = $this->submissions->generate($validated['pack_type'], $request->user(), $validated);

        return redirect()
            ->route('submissions.show', $pack)
            ->with('success', "{$pack->pack_ref} generated at {$pack->completeness_score}% complete.");
    }

    public function update(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('update', $pack);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $this->submissions->update($pack, $request->user(), $validated['answers']);

        return back()->with('success', "Saved — the pack is now {$pack->completeness_score}% complete.");
    }

    public function regenerate(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('update', $pack);

        $this->submissions->regenerate($pack, $request->user());

        return back()->with('success', "Rebuilt against current data — {$pack->completeness_score}% complete.");
    }

    public function sendForReview(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('sendForReview', $pack);

        $this->submissions->sendForReview($pack, $request->user());

        return back()->with('success', 'Sent for review. Someone other than you must review it.');
    }

    public function review(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('review', $pack);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'return'])],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,return'],
        ]);

        $this->submissions->review($pack, $request->user(), $validated['decision'], $validated['notes'] ?? null);

        return back()->with('success', $validated['decision'] === 'accept'
            ? 'Reviewed. A third person must now approve it.'
            : 'Returned to draft with your notes.');
    }

    public function approve(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('approve', $pack);

        $this->submissions->approve($pack, $request->user());
        $this->submissions->render($pack, $request->user());

        return back()->with('success', "Approved and locked. Checksum {$pack->checksum}.");
    }

    public function file(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('file', $pack);

        $validated = $request->validate([
            'submission_reference' => ['nullable', 'string', 'max:150'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $this->submissions->file($pack, $request->user(), $validated);

        return back()->with('success', "Recorded as filed with the regulator ({$pack->pack_ref}).");
    }

    public function acknowledge(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('acknowledge', $pack);

        $validated = $request->validate([
            'acknowledgement_ref' => ['nullable', 'string', 'max:150'],
            'acknowledgement' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        if ($request->hasFile('acknowledgement')) {
            $file = $request->file('acknowledgement');

            $validated['acknowledgement_path'] = $this->submissions->storeAcknowledgement(
                $pack,
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalExtension(),
            );
        }

        $this->submissions->acknowledge($pack, $request->user(), $validated);

        return back()->with('success', 'Acknowledgement recorded.');
    }

    public function reject(Request $request, SubmissionPack $pack): RedirectResponse
    {
        $this->authorize('reject', $pack);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $replacement = $this->submissions->reject($pack, $request->user(), $validated['reason']);

        return back()->with('success', "Rejection recorded on {$replacement->pack_ref}. Re-submit as a new version.");
    }

    /** Render and download the pack document in the requested format. */
    public function download(Request $request, SubmissionPack $pack): StreamedResponse
    {
        $this->authorize('download', $pack);

        $format = $request->query('format', 'pdf');
        abort_unless(in_array($format, ['pdf', 'docx', 'xlsx'], true), 422);

        $run = $this->submissions->render($pack, $request->user(), $format);

        abort_unless($run->status === 'Completed', 500, $run->error_message ?? 'The document could not be rendered.');

        $disk = Storage::disk(config('dashboards.report_disk'));

        return $disk->download($run->output_path, $pack->pack_ref.'.'.$format);
    }
}
