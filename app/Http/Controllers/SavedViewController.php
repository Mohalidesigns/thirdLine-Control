<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SavedViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['resource' => ['required', 'string', 'max:50']]);

        return response()->json([
            'views' => SavedView::visibleTo($request->user(), $request->input('resource'))
                ->with('user:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (SavedView $view) => [
                    ...$view->only(['id', 'name', 'resource', 'filters', 'is_shared', 'is_default']),
                    'owner' => $view->user?->name,
                    'is_mine' => $view->user_id === $request->user()->id,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'resource' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['present', 'array'],
            'is_shared' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ]);

        if ($data['is_default']) {
            SavedView::where('user_id', $request->user()->id)
                ->where('resource', $data['resource'])
                ->update(['is_default' => false]);
        }

        SavedView::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'resource' => $data['resource'],
                'name' => $data['name'],
            ],
            [
                'tenant_id' => $request->user()->tenant_id,
                'filters' => $data['filters'],
                'is_shared' => $data['is_shared'],
                'is_default' => $data['is_default'],
            ],
        );

        return back()->with('success', "View '{$data['name']}' saved.");
    }

    public function setDefault(Request $request, SavedView $savedView): RedirectResponse
    {
        abort_unless(
            $savedView->user_id === $request->user()->id
            || ($savedView->is_shared && SavedView::visibleTo($request->user(), $savedView->resource)->whereKey($savedView->id)->exists()),
            403,
        );

        SavedView::where('user_id', $request->user()->id)
            ->where('resource', $savedView->resource)
            ->update(['is_default' => false]);

        // Defaulting someone else's shared view clones it as your own marker.
        if ($savedView->user_id === $request->user()->id) {
            $savedView->update(['is_default' => true]);
        } else {
            SavedView::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'resource' => $savedView->resource,
                    'name' => $savedView->name,
                ],
                [
                    'tenant_id' => $request->user()->tenant_id,
                    'filters' => $savedView->filters,
                    'is_shared' => false,
                    'is_default' => true,
                ],
            );
        }

        return back()->with('success', 'Default view set.');
    }

    public function destroy(Request $request, SavedView $savedView): RedirectResponse
    {
        abort_unless($savedView->user_id === $request->user()->id, 403);

        $savedView->delete();

        return back()->with('success', 'View deleted.');
    }
}
