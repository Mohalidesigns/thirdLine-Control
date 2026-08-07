<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves feature flags: tenant row overrides global row; a rollout
 * percentage below 100 admits users deterministically (stable per
 * user+key, so a user never flip-flops between requests).
 */
class FeatureService
{
    /** @var array<string, Collection>|null memoised per request */
    private ?array $flags = null;

    public function enabled(string $key, ?User $user = null): bool
    {
        $user ??= auth()->user();
        $flag = $this->resolve($key, $user?->tenant_id);

        if (! $flag || ! $flag->is_enabled) {
            return false;
        }

        if ($flag->rollout_percentage >= 100) {
            return true;
        }

        return $this->bucket($key, $user?->id ?? 0) < $flag->rollout_percentage;
    }

    /**
     * Every enabled flag key for the given user — shared with the frontend
     * so React pages can gate navigation and components.
     *
     * @return array<int, string>
     */
    public function enabledKeys(?User $user = null): array
    {
        $user ??= auth()->user();

        return $this->all($user?->tenant_id)
            ->keys()
            ->filter(fn (string $key) => $this->enabled($key, $user))
            ->values()
            ->all();
    }

    public function flush(): void
    {
        $this->flags = null;
    }

    /**
     * Create or update a tenant's override (or the global default when
     * $tenantId is null). Audited via the model's Auditable trait.
     */
    public function set(?int $tenantId, string $key, bool $isEnabled, int $rolloutPercentage = 100, ?string $description = null): FeatureFlag
    {
        $flag = FeatureFlag::withoutGlobalScopes()
            ->firstOrNew(['tenant_id' => $tenantId, 'key' => $key]);

        $flag->fill([
            'is_enabled' => $isEnabled,
            'rollout_percentage' => max(0, min(100, $rolloutPercentage)),
            'description' => $description ?? $flag->description,
        ])->save();

        $this->flush();

        return $flag;
    }

    private function resolve(string $key, ?int $tenantId): ?FeatureFlag
    {
        return $this->all($tenantId)->get($key);
    }

    private function all(?int $tenantId): Collection
    {
        $cacheKey = (string) ($tenantId ?? 'global');

        if (isset($this->flags[$cacheKey])) {
            return $this->flags[$cacheKey];
        }

        return $this->flags[$cacheKey] = FeatureFlag::query()
            ->whereNull('tenant_id')
            ->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is not null')   // globals first, tenant rows overwrite on keyBy
            ->get()
            ->keyBy('key');
    }

    private function bucket(string $key, int $userId): int
    {
        return hexdec(substr(hash('sha256', "{$key}:{$userId}"), 0, 8)) % 100;
    }
}
