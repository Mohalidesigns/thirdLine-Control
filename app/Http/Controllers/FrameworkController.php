<?php

namespace App\Http\Controllers;

use App\Http\Requests\FrameworkRequest;
use App\Models\Framework;
use App\Models\OrganisationUnit;
use App\Services\FrameworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FrameworkController extends Controller
{
    public function __construct(private FrameworkService $frameworks) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Framework::class);

        $frameworks = Framework::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->when($request->jurisdiction, fn ($q, $j) => $q->where('jurisdiction', $j))
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->when($request->verification_status, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->withCount('requirements')
            ->orderBy('jurisdiction')
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Frameworks/Index', [
            'frameworks' => $frameworks,
            'filters' => $request->only(['search', 'jurisdiction', 'category', 'verification_status', 'active_only']),
            'categories' => Framework::CATEGORIES,
            'verificationStatuses' => Framework::VERIFICATION_STATUSES,
            'jurisdictions' => Framework::query()->distinct()->orderBy('jurisdiction')->pluck('jurisdiction'),
            'stats' => [
                'total' => Framework::query()->count(),
                'verified' => Framework::query()->verified()->count(),
                'nigerian' => Framework::query()->where('jurisdiction', 'NG')->count(),
                'certifiable' => Framework::query()->where('is_certifiable', true)->count(),
            ],
            'can' => ['manage' => $request->user()->can('create', Framework::class)],
        ]);
    }

    public function show(Request $request, Framework $framework): Response
    {
        $this->authorize('view', $framework);

        return Inertia::render('Frameworks/Show', [
            'framework' => $framework->loadCount('requirements'),
            'tree' => $this->frameworks->tree($framework),
            'coverage' => $this->frameworks->coverage($framework),
            'can' => [
                'manage' => $request->user()->can('update', $framework),
                'map' => $request->user()->can('map controls'),
                'approveMappings' => $request->user()->can('approve control-mappings'),
            ],
        ]);
    }

    /**
     * Requirements × entities heatmap. Entity coverage is computed once per
     * entity, so the page is a fixed number of queries regardless of size.
     */
    public function coverage(Request $request, Framework $framework): Response
    {
        $this->authorize('view', $framework);

        $entities = OrganisationUnit::query()
            ->when($request->entity_type, fn ($q, $t) => $q->where('entity_type', $t))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'entity_type']);

        return Inertia::render('Frameworks/Coverage', [
            'framework' => $framework,
            'matrix' => $this->frameworks->coverageMatrix($framework, $entities),
            'summary' => $this->frameworks->coverage($framework),
            'perEntity' => $entities->map(fn ($entity) => [
                'entity' => ['id' => $entity->id, 'name' => $entity->name],
                ...$this->frameworks->coverage($framework, $entity->id),
            ])->values(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Framework::class);

        return Inertia::render('Frameworks/Create', [
            'categories' => Framework::CATEGORIES,
            'verificationStatuses' => Framework::VERIFICATION_STATUSES,
        ]);
    }

    public function store(FrameworkRequest $request): RedirectResponse
    {
        $this->authorize('create', Framework::class);

        $framework = Framework::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return redirect()->route('frameworks.show', $framework)
            ->with('success', "Framework {$framework->code} created.");
    }

    public function update(FrameworkRequest $request, Framework $framework): RedirectResponse
    {
        $this->authorize('update', $framework);

        $framework->update($request->validated());

        return back()->with('success', "Framework {$framework->code} updated.");
    }

    public function destroy(Framework $framework): RedirectResponse
    {
        $this->authorize('delete', $framework);

        $framework->delete();

        return redirect()->route('frameworks.index')->with('success', 'Framework removed.');
    }

    /** Export in content-pack shape so a customised framework can be shipped back. */
    public function export(Framework $framework): StreamedResponse
    {
        $this->authorize('export', $framework);

        $framework->auditAction('exported');

        $payload = json_encode($this->frameworks->export($framework), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $filename = strtolower($framework->code).'-'.$framework->version.'.json';

        return response()->streamDownload(fn () => print ($payload), $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('import', Framework::class);

        $request->validate(['pack' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:8192']]);

        $pack = json_decode($request->file('pack')->get(), true);

        if (! is_array($pack) || empty($pack['framework']['code'])) {
            return back()->withErrors(['pack' => 'That file is not a valid framework pack.']);
        }

        $framework = $this->frameworks->import($pack, $request->user()->tenant_id, $request->user());

        return redirect()->route('frameworks.show', $framework)
            ->with('success', "Imported {$framework->code} with ".count($pack['requirements'] ?? []).' requirement(s).');
    }

    /** Headline coverage for every framework — feeds dashboards and the API. */
    public function coverageSummary(): JsonResponse
    {
        $this->authorize('viewAny', Framework::class);

        return response()->json([
            'data' => Framework::query()->active()->get()
                ->map(fn (Framework $f) => $this->frameworks->coverage($f))
                ->values(),
        ]);
    }
}
