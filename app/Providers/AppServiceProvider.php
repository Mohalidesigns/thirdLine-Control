<?php

namespace App\Providers;

use App\Listeners\QueueKnowledgeIndexing;
use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\ControlUnit;
use App\Models\CrossBorderTransfer;
use App\Models\Entity;
use App\Models\ExceptionAction;
use App\Models\ExceptionEscalation;
use App\Models\ExceptionResponse;
use App\Models\InvestigationCase;
use App\Models\OrganisationUnit;
use App\Models\SsoConfiguration;
use App\Models\TenantBranding;
use App\Models\TestInstance;
use App\Models\User;
use App\Observers\OrganisationUnitObserver;
use App\Policies\AiConfigurationPolicy;
use App\Policies\AiInteractionPolicy;
use App\Policies\CompensatingControlPolicy;
use App\Policies\ControlEntityPolicy;
use App\Policies\ControlPolicy;
use App\Policies\ControlUnitPolicy;
use App\Policies\CrossBorderTransferPolicy;
use App\Policies\EntityPolicy;
use App\Policies\ExceptionActionPolicy;
use App\Policies\ExceptionEscalationPolicy;
use App\Policies\ExceptionPolicy;
use App\Policies\ExceptionResponsePolicy;
use App\Policies\SsoConfigurationPolicy;
use App\Policies\TestInstancePolicy;
use App\Services\Ai\KnowledgeIndexer;
use App\Services\FeatureService;
use App\Services\ResidencyGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
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
        // DEF-019 — the machine surface must be throttled like the human
        // one. Keyed by caller IP so invalid-key probing shares one bucket
        // rather than getting a fresh allowance per guessed key.
        RateLimiter::for('integration-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // DEF-008 — `exists:users,id` proves a row exists in ANY tenant.
        // Every user reference in a form must resolve inside the caller's
        // own tenant, or a foreign user can silently be made responsible
        // for our records. Use `tenant_user` wherever a form accepts a
        // user id.
        Validator::extend('tenant_user', function (string $attribute, mixed $value) {
            return User::query()
                ->whereKey($value)
                ->where('tenant_id', auth()->user()?->tenant_id)
                ->exists();
        }, 'The selected user does not belong to this organisation.');

        Gate::policy(Control::class, ControlPolicy::class);
        Gate::policy(TestInstance::class, TestInstancePolicy::class);
        Gate::policy(ControlException::class, ExceptionPolicy::class);
        Gate::policy(CompensatingControl::class, CompensatingControlPolicy::class);
        Gate::policy(SsoConfiguration::class, SsoConfigurationPolicy::class);
        Gate::policy(AiInteraction::class, AiInteractionPolicy::class);
        Gate::policy(AiConfiguration::class, AiConfigurationPolicy::class);
        Gate::policy(Entity::class, EntityPolicy::class);
        Gate::policy(CrossBorderTransfer::class, CrossBorderTransferPolicy::class);
        // CR-01 — the Exception Manager loop.
        Gate::policy(ExceptionEscalation::class, ExceptionEscalationPolicy::class);
        Gate::policy(ExceptionResponse::class, ExceptionResponsePolicy::class);
        Gate::policy(ExceptionAction::class, ExceptionActionPolicy::class);
        // CR2-A — the Internal Control structure.
        Gate::policy(ControlUnit::class, ControlUnitPolicy::class);
        Gate::policy(ControlEntity::class, ControlEntityPolicy::class);

        // CR2-A: a newly created Branch unit is provisioned under Branch
        // Control immediately, not on the next nightly sync.
        OrganisationUnit::observe(OrganisationUnitObserver::class);

        $this->registerKnowledgeIndexing();

        // Residency guard at the queue layer (16.2): every dispatch checks
        // the broker's declared region before the payload is created. This
        // hook has no off switch — the guard cannot be disabled at runtime.
        Queue::createPayloadUsing(function (?string $connection) {
            app(ResidencyGuard::class)->assertQueueAllowed($connection);

            return [];
        });

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

    /**
     * Keep the AI knowledge index in step with the records it describes
     * (Phase 14.3). Saving marks the record's chunks stale and queues a
     * rebuild; the write itself is never blocked, and QueueKnowledgeIndexing
     * swallows its own failures, because a search index must not be able to
     * veto a business save.
     */
    private function registerKnowledgeIndexing(): void
    {
        foreach (KnowledgeIndexer::sourceMap() as $class) {
            $class::saved(fn ($record) => app(QueueKnowledgeIndexing::class)->saved($record));
            $class::deleted(fn ($record) => app(QueueKnowledgeIndexing::class)->deleted($record));
        }
    }
}
