<?php

namespace App\Http\Middleware;

use App\Models\TenantBranding;
use App\Services\FeatureService;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'position']),
                'roles' => $user?->getRoleNames()->toArray() ?? [],
                'permissions' => $user?->getAllPermissions()->pluck('name')->toArray() ?? [],
            ],
            'unreadNotifications' => $user ? $user->unreadNotifications()->count() : 0,
            'features' => fn () => $user ? app(FeatureService::class)->enabledKeys($user) : [],
            'lowBandwidth' => (bool) $user?->low_bandwidth_mode,
            // Branch-per-client: guests see the installation's sole active
            // tenant branding; authenticated users see their tenant's.
            'branding' => fn () => app(FeatureService::class)->enabled('branding', $user)
                ? TenantBranding::withoutGlobalScope('tenant')
                    ->when($user, fn ($q) => $q->where('tenant_id', $user->tenant_id))
                    ->first()?->toSharedProps()
                : null,
            'localisation' => fn () => $user?->tenant?->localisation() ?? [
                'locale' => 'en-NG',
                'timezone' => 'Africa/Lagos',
                'currency' => 'NGN',
                'date_format' => 'DD MMM YYYY',
                'fiscal_year_start_month' => 1,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
            // Content-pack dry-run report (Phase 8) — flashed, not persisted.
            'packPlan' => fn () => $request->session()->get('packPlan'),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
