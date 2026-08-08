<?php

namespace App\Providers;

use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\InvestigationCase;
use App\Models\SsoConfiguration;
use App\Models\TenantBranding;
use App\Models\TestInstance;
use App\Policies\CompensatingControlPolicy;
use App\Policies\ControlPolicy;
use App\Policies\ExceptionPolicy;
use App\Policies\SsoConfigurationPolicy;
use App\Policies\TestInstancePolicy;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FeatureService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Control::class, ControlPolicy::class);
        Gate::policy(TestInstance::class, TestInstancePolicy::class);
        Gate::policy(ControlException::class, ExceptionPolicy::class);
        Gate::policy(CompensatingControl::class, CompensatingControlPolicy::class);
        Gate::policy(SsoConfiguration::class, SsoConfigurationPolicy::class);

        // Cases resolve without the allowlist scope so the *policy* is what
        // denies a non-member, giving an explicit 403 rather than a 404 that
        // could be mistaken for a deleted record. Tenant scoping still
        // applies, so another tenant's case remains simply not found.
        Route::bind('case', fn ($value) => InvestigationCase::withoutGlobalScope('allowlist')
            ->where('id', $value)
            ->firstOrFail());

        Vite::prefetch(concurrency: 3);

        // Tenant branding flows into PDF reports (Phase 7.3). The composer
        // resolves lazily at render time, inside an authenticated request.
        View::composer('reports.layout', function ($view) {
            $branding = auth()->check()
                ? TenantBranding::firstWhere('tenant_id', auth()->user()->tenant_id)
                : null;

            $view->with('branding', $branding);

            if ($branding?->email_from_name) {
                config(['mail.from.name' => $branding->email_from_name]);
            }
        });

        // Branding also names outbound mail. Branch-per-client: one tenant
        // per installation, so a boot-time override is safe. Guarded — the
        // table may not exist yet mid-migration.
        try {
            if (Schema::hasTable('tenant_brandings')) {
                $fromName = TenantBranding::withoutGlobalScope('tenant')->value('email_from_name');
                if ($fromName) {
                    config(['mail.from.name' => $fromName]);
                }
            }
        } catch (\Throwable) {
            // Database unavailable (first boot, artisan key:generate…) — defaults apply.
        }
    }
}
