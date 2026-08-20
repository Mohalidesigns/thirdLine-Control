<?php

namespace App\Http\Controllers;

use App\Models\AssuranceActivity;
use App\Models\AssuranceProvider;
use App\Models\Entity;
use App\Services\AssuranceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssuranceController extends Controller
{
    public function __construct(private AssuranceService $assurance) {}

    public function map(Request $request): Response
    {
        abort_unless($request->user()->can('view assurance'), 403);

        $period = $request->period;

        // Built once and used for both the matrix and the headline numbers —
        // the stats are derived from the map, not a second pass over it.
        $map = $this->assurance->map($request->user()->tenant_id, $period, $request->entity_id);

        return Inertia::render('Assurance/Map', [
            'map' => $map,
            'stats' => $this->assurance->stats($request->user()->tenant_id, $period, $map),
            'filters' => $request->only(['period', 'entity_id']),
            'periods' => AssuranceActivity::query()
                ->whereNotNull('period_label')
                ->distinct()
                ->orderByDesc('period_label')
                ->pluck('period_label'),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'options' => [
                'lines' => collect(AssuranceProvider::LINES)
                    ->map(fn ($line) => ['value' => $line, 'label' => AssuranceProvider::LINE_LABELS[$line]])
                    ->all(),
                'coverage_levels' => AssuranceActivity::COVERAGE_LEVELS,
                'assurance_types' => AssuranceActivity::ASSURANCE_TYPES,
                'conclusions' => AssuranceActivity::CONCLUSIONS,
                'reliance_levels' => AssuranceActivity::RELIANCE_LEVELS,
                'subject_types' => collect(array_keys(AssuranceActivity::SUBJECT_TYPES))
                    ->map(fn ($type) => ['value' => $type, 'label' => AssuranceActivity::SUBJECT_LABELS[$type]])
                    ->all(),
            ],
            'can' => [
                'manage' => $request->user()->can('manage assurance'),
                'reliance' => $request->user()->can('record reliance'),
            ],
        ]);
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage assurance'), 403);

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('assurance_providers', 'code')
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'line' => ['required', Rule::in(AssuranceProvider::LINES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_independent' => ['nullable', 'boolean'],
        ]);

        $provider = AssuranceProvider::create($validated);

        return back()->with('success', "{$provider->name} added to the assurance provider register.");
    }

    public function storeActivity(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage assurance'), 403);

        $validated = $request->validate([
            'provider_id' => ['required', 'exists:assurance_providers,id'],
            'entity_id' => ['nullable', 'exists:entities,id'],
            'subject_type' => ['required', Rule::in(array_keys(AssuranceActivity::SUBJECT_TYPES))],
            'subject_id' => ['required', 'integer', 'min:1'],
            'activity_name' => ['nullable', 'string', 'max:255'],
            'coverage_level' => ['required', Rule::in(AssuranceActivity::COVERAGE_LEVELS)],
            'assurance_type' => ['required', Rule::in(AssuranceActivity::ASSURANCE_TYPES)],
            'last_assurance_date' => ['nullable', 'date'],
            'next_assurance_date' => ['nullable', 'date', 'after_or_equal:last_assurance_date'],
            'period_label' => ['nullable', 'string', 'max:40'],
            'conclusion' => ['required', Rule::in(AssuranceActivity::CONCLUSIONS)],
            'scope_note' => ['nullable', 'string', 'max:2000'],
        ]);

        AssuranceActivity::updateOrCreate(
            [
                'provider_id' => $validated['provider_id'],
                'subject_type' => $validated['subject_type'],
                'subject_id' => $validated['subject_id'],
                'period_label' => $validated['period_label'] ?? null,
            ],
            [...$validated, 'source' => 'manual'],
        );

        return back()->with('success', 'Assurance coverage recorded.');
    }

    /**
     * Record how far the board may rely on a provider's work — the decision
     * a combined assurance report is signed on the strength of.
     */
    public function recordReliance(Request $request, AssuranceActivity $activity): RedirectResponse
    {
        abort_unless($request->user()->can('record reliance'), 403);

        $validated = $request->validate([
            'reliance_level' => ['required', Rule::in(AssuranceActivity::RELIANCE_LEVELS)],
            'reliance_rationale' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assurance->recordReliance($activity, $request->user(), $validated);

        return back()->with('success', 'Reliance decision recorded.');
    }
}
