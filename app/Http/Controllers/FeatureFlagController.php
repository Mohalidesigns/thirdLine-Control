<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeatureFlagRequest;
use App\Models\FeatureFlag;
use App\Services\FeatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeatureFlagController extends Controller
{
    public function __construct(private FeatureService $features) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage settings'), 403);

        $tenantId = $request->user()->tenant_id;

        $flags = FeatureFlag::query()
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', $tenantId)
            ->orderBy('key')
            ->get()
            ->groupBy('key')
            ->map(function ($rows, $key) {
                $global = $rows->first(fn ($r) => $r->tenant_id === null);
                $tenant = $rows->first(fn ($r) => $r->tenant_id !== null);

                return [
                    'key' => $key,
                    'description' => $global?->description ?? $tenant?->description,
                    'global' => $global?->only(['is_enabled', 'rollout_percentage']),
                    'tenant' => $tenant?->only(['is_enabled', 'rollout_percentage']),
                ];
            })
            ->values();

        return Inertia::render('Admin/FeatureFlags', ['flags' => $flags]);
    }

    public function update(FeatureFlagRequest $request, string $key): RedirectResponse
    {
        $data = $request->validated();
        $tenantId = $data['scope'] === 'global' ? null : $request->user()->tenant_id;

        $this->features->set($tenantId, $key, $data['is_enabled'], $data['rollout_percentage']);

        return back()->with('success', "Feature '{$key}' updated.");
    }
}
