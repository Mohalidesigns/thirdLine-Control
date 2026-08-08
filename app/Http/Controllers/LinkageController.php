<?php

namespace App\Http\Controllers;

use App\Http\Requests\EntityLinkRequest;
use App\Models\EntityLink;
use App\Services\LinkageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LinkageController extends Controller
{
    public function __construct(private LinkageService $linkage) {}

    public function store(EntityLinkRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->linkage->link(
            $data['source_type'], $data['source_id'],
            $data['target_type'], $data['target_id'],
            $data['relationship'], $data['strength'] ?? null, $data['notes'] ?? null,
        );

        return back()->with('success', 'Relationship recorded.');
    }

    public function destroy(Request $request, EntityLink $link): RedirectResponse
    {
        abort_unless($request->user()->can('manage linkage'), 403);

        $this->linkage->unlink($link);

        return back()->with('success', 'Relationship removed.');
    }

    /**
     * Server-computed adjacency for the graph view — the browser never
     * walks the register itself (R6).
     */
    public function graph(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless($request->user()->can('view risks') || $request->user()->can('view metrics'), 403);

        $validated = $request->validate([
            'hops' => ['nullable', 'integer', 'between:1,3'],
        ]);

        return response()->json(
            $this->linkage->graph($type, $id, $validated['hops'] ?? 2)
        );
    }

    public function candidates(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage linkage'), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(EntityLink::NODE_TYPES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(
            $this->linkage->candidates($validated['type'], $validated['search'] ?? null)
        );
    }
}
