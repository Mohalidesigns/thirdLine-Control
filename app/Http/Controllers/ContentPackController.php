<?php

namespace App\Http\Controllers;

use App\Models\ContentPack;
use App\Services\ContentPackInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ContentPackController extends Controller
{
    public function __construct(private ContentPackInstaller $installer) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ContentPack::class);

        $installed = ContentPack::with('installer:id,name')->get()->keyBy(fn ($p) => $p->code.'@'.$p->version);

        $available = collect($this->installer->availablePacks())
            ->map(function (array $versions, string $code) use ($installed) {
                $latest = end($versions);
                $record = $installed->get($code.'@'.$latest);

                return [
                    'code' => $code,
                    'versions' => $versions,
                    'latest' => $latest,
                    'installed_version' => $installed->filter(fn ($p) => $p->code === $code)
                        ->sortByDesc('installed_at')->first()?->version,
                    'installed_at' => $record?->installed_at,
                    'installed_by' => $record?->installer?->name,
                    'name' => $record?->name,
                    'jurisdiction' => $record?->jurisdiction,
                    'verification_summary' => $record?->verification_summary,
                ];
            })
            ->values();

        return Inertia::render('Admin/ContentPacks', [
            'packs' => $available,
            'installed' => ContentPack::with('installer:id,name')->orderByDesc('installed_at')->get(),
            'can' => ['install' => $request->user()->can('install', ContentPack::class)],
        ]);
    }

    /** Dry run first: an operator sees the diff before anything is written. */
    public function preview(Request $request): RedirectResponse
    {
        $this->authorize('install', ContentPack::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'version' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $result = $this->installer->install($data['code'], $data['version'] ?? null, dryRun: true);
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('packPlan', $result['plan']);
    }

    public function install(Request $request): RedirectResponse
    {
        $this->authorize('install', ContentPack::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'version' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $result = $this->installer->install(
                $data['code'], $data['version'] ?? null, dryRun: false, user: $request->user()
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $plan = $result['plan'];
        $redirect = back()->with('success', "{$data['code']} {$plan['version']} installed.");

        if ($plan['verification_summary']['pack'] !== 'verified') {
            $redirect->with('warning', 'This pack is not verified — its records are excluded from '
                .'generated regulatory submissions until a human confirms them against the regulator’s primary document.');
        }

        return $redirect;
    }
}
