<?php

namespace App\Http\Controllers;

use App\Models\TenantBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PwaController extends Controller
{
    /** Web app manifest, tenant-branded when branding exists (Phase 7.10). */
    public function manifest(): JsonResponse
    {
        $branding = TenantBranding::withoutGlobalScope('tenant')->first();

        return response()->json([
            'name' => $branding?->product_name ?? config('app.name', 'SecondLine'),
            'short_name' => $branding?->product_name ?? 'SecondLine',
            'description' => 'Internal control & GRC platform',
            'start_url' => '/dashboard',
            'display' => 'standalone',
            'background_color' => $branding?->primary_colour ?? '#0B1F3A',
            'theme_color' => $branding?->primary_colour ?? '#0B1F3A',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /** Offline fallback page, precached by the service worker. */
    public function offline()
    {
        return view('offline');
    }

    /** Toggle the user's low-bandwidth mode. */
    public function toggleLowBandwidth(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'low_bandwidth_mode' => ! $request->user()->low_bandwidth_mode,
        ])->save();

        return back();
    }
}
