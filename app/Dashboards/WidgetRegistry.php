<?php

namespace App\Dashboards;

use App\Dashboards\Sources\ComplianceSources;
use App\Dashboards\Sources\ControlSources;
use App\Dashboards\Sources\ExceptionSources;
use App\Dashboards\Sources\GovernanceSources;
use App\Dashboards\Sources\MonitoringSources;
use App\Dashboards\Sources\OrganisationSources;
use App\Dashboards\Sources\PredictiveSources;
use App\Dashboards\Sources\RiskSources;
use App\Dashboards\Sources\TestingSources;
use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The allowlist between a saved dashboard and the database (13.2).
 *
 * Resolution is deliberately fail-closed in three ways: an unknown key
 * resolves to nothing, a key the viewer lacks permission for resolves to a
 * denial rather than to data, and a resolver that throws returns an error
 * state instead of taking the whole dashboard down with it.
 */
class WidgetRegistry
{
    /** @var array<int, class-string> */
    private const PROVIDERS = [
        ControlSources::class,
        TestingSources::class,
        ExceptionSources::class,
        RiskSources::class,
        ComplianceSources::class,
        GovernanceSources::class,
        MonitoringSources::class,
        OrganisationSources::class,
        PredictiveSources::class,
    ];

    /** @var array<string, WidgetSource>|null */
    private ?array $sources = null;

    /** @return array<string, WidgetSource> */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];

        foreach (self::PROVIDERS as $provider) {
            foreach (app($provider)->sources() as $source) {
                $sources[$source->key] = $source;
            }
        }

        return $this->sources = $sources;
    }

    public function find(string $key): ?WidgetSource
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * The builder's widget palette, already filtered — a user cannot place a
     * tile they would not be allowed to read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableTo(User $user): array
    {
        return array_values(array_map(
            fn (WidgetSource $source) => $source->toArray(),
            array_filter($this->all(), fn (WidgetSource $s) => $s->visibleTo($user)),
        ));
    }

    /**
     * Resolve one widget for one viewer.
     *
     * @return array<string, mixed> the payload, or a denied/error state
     */
    public function resolveWidget(User $user, DashboardWidget $widget): array
    {
        $source = $widget->data_source_key ? $this->find($widget->data_source_key) : null;

        if ($widget->widget_type === 'text') {
            return ['kind' => 'text', 'body' => (string) ($widget->display_config['body'] ?? '')];
        }

        if (! $source) {
            return ['kind' => 'error', 'message' => 'This tile points at a dataset that no longer exists.'];
        }

        // Two gates, both closed by default: the source's own permission and
        // any narrower one the tenant put on the tile. A tile can restrict
        // further than the source, never wider.
        if (! $source->visibleTo($user)
            || ($widget->permission_required && ! $user->can($widget->permission_required))) {
            return ['kind' => 'denied', 'message' => 'You do not have access to this data.'];
        }

        return $this->resolve($user, $source, $widget->query_config ?? []);
    }

    /** @return array<string, mixed> */
    public function resolve(User $user, WidgetSource $source, array $config = []): array
    {
        $ttl = (int) config('dashboards.cache_ttl');

        $run = function () use ($user, $source, $config) {
            try {
                return $source->resolve($user, $config) + ['kind' => 'scalar'];
            } catch (\Throwable $e) {
                Log::error('Dashboard widget failed to resolve', [
                    'source' => $source->key,
                    'user' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return ['kind' => 'error', 'message' => 'This tile could not be calculated.'];
            }
        };

        if ($ttl <= 0) {
            return $run();
        }

        return Cache::remember($this->cacheKey($user, $source, $config), $ttl, $run);
    }

    /**
     * The permission fingerprint in the key is what makes caching safe: two
     * viewers with different entitlements never share a cached tile, and a
     * self-scoped source is keyed to the individual.
     */
    private function cacheKey(User $user, WidgetSource $source, array $config): string
    {
        $scope = $source->selfScoped
            ? 'u'.$user->id
            : 'p'.substr(md5(implode('|', $user->getAllPermissions()->pluck('name')->sort()->all())), 0, 12);

        return sprintf(
            'dashboard:%d:%s:%s:%s',
            $user->tenant_id,
            $source->key,
            $scope,
            substr(md5(json_encode($config) ?: ''), 0, 12),
        );
    }
}
