<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function __construct(private ImportService $importService) {}

    public function index()
    {
        abort_unless(auth()->user()->can('import data'), 403);

        return Inertia::render('Admin/Import', [
            'resources' => collect(ImportService::RESOURCES)->map(fn ($resource) => [
                'key' => $resource,
                'columns' => collect($this->importService->columns($resource))
                    ->map(fn ($col, $key) => ['key' => $key, 'label' => $col[0], 'required' => $col[1]])
                    ->values(),
            ])->values(),
        ]);
    }

    public function template(Request $request, string $resource)
    {
        abort_unless($request->user()->can('import data'), 403);
        abort_unless(in_array($resource, ImportService::RESOURCES, true), 404);

        return $this->importService->template($resource);
    }

    /** Row-level validation report — writes nothing, ever. */
    public function dryRun(Request $request, string $resource)
    {
        abort_unless($request->user()->can('import data'), 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'resource' => [Rule::in(ImportService::RESOURCES)],
        ]);

        return response()->json(
            $this->importService->dryRun($resource, $request->file('file'), $request->user()),
        );
    }

    public function store(Request $request, string $resource)
    {
        abort_unless($request->user()->can('import data'), 403);
        abort_unless(in_array($resource, ImportService::RESOURCES, true), 404);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']]);

        $result = $this->importService->import($resource, $request->file('file'), $request->user());

        return back()->with('success', "{$result['created']} ".str_replace('-', ' ', $resource).' imported.');
    }
}
