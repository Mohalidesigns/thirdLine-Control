<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\Objective;
use App\Services\ObjectiveService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two board-facing views of the strategy layer (17.1): the map, which
 * shows objectives by perspective with the risks that threaten them, and
 * the balanced scorecard, which shows the measures behind each number.
 */
class StrategyController extends Controller
{
    public function __construct(private ObjectiveService $objectives) {}

    public function map(Request $request): Response
    {
        $this->authorize('viewAny', Objective::class);

        return Inertia::render('Strategy/Map', [
            'map' => $this->objectives->strategyMap($request->period, $request->entity_id),
            'filters' => $request->only(['period', 'entity_id']),
            'periods' => $this->objectives->periods(),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'bands' => ObjectiveService::BANDS,
        ]);
    }

    public function scorecard(Request $request): Response
    {
        $this->authorize('viewAny', Objective::class);

        return Inertia::render('Strategy/Scorecard', [
            'scorecard' => $this->objectives->scorecard($request->period, $request->entity_id),
            'filters' => $request->only(['period', 'entity_id']),
            'periods' => $this->objectives->periods(),
            'entities' => Entity::query()->orderBy('legal_name')->get(['id', 'legal_name']),
            'bands' => ObjectiveService::BANDS,
        ]);
    }
}
