<?php

namespace App\Services;

use App\Exceptions\ResidencyViolationException;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the per-tenant data-residency declaration at the four layers
 * data can leave through: filesystem writes, queue dispatch, backups and
 * outbound integrations (16.2).
 *
 * The guard is configuration-only: there is no database flag, feature
 * flag or environment switch that turns it off, so it cannot be disabled
 * at runtime. Every block is audited against the tenant (R3).
 */
class ResidencyGuard
{
    public function __construct(private AuditTrailService $audit) {}

    public function assertStorageAllowed(string $disk, ?Tenant $tenant = null): void
    {
        $this->assert('filesystem', $disk, config("residency.disk_regions.{$disk}"), $tenant);
    }

    public function assertQueueAllowed(?string $connection, ?Tenant $tenant = null): void
    {
        $connection ??= config('queue.default');

        $this->assert('queue', $connection, config("residency.queue_regions.{$connection}"), $tenant);
    }

    public function assertBackupAllowed(?string $disk = null, ?Tenant $tenant = null): void
    {
        $disk ??= config('residency.backup_disk');

        $this->assert('backup', $disk, config("residency.disk_regions.{$disk}"), $tenant);
    }

    /**
     * Integration layer: block an outbound call whose host is declared to
     * sit in another country. An unmapped host is assumed in-plane —
     * production maps every integration endpoint (docs/deployment) — so
     * existing integrations keep working unchanged (R8).
     */
    public function assertEndpointAllowed(?string $url, ?Tenant $tenant = null): void
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        if (! $host) {
            return;
        }

        foreach (config('residency.endpoint_regions', []) as $suffix => $region) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $this->assert('integration', $host, $region, $tenant);

                return;
            }
        }
    }

    public function declaredCountry(?Tenant $tenant = null): string
    {
        return $tenant?->data_residency
            ?? auth()->user()?->tenant?->data_residency
            ?? config('residency.default_country');
    }

    private function assert(string $layer, string $target, ?string $region, ?Tenant $tenant): void
    {
        // '*' is in-process / same-plane; an unconfigured target inherits
        // the plane it runs in (deployment docs require mapping every
        // production disk and broker).
        if ($region === null || $region === '*') {
            return;
        }

        $declared = $this->declaredCountry($tenant);

        if (strcasecmp($region, $declared) === 0) {
            return;
        }

        $this->block($layer, $target, $region, $declared, $tenant);
    }

    private function block(string $layer, string $target, string $region, string $declared, ?Tenant $tenant): never
    {
        $tenant ??= auth()->user()?->tenant;

        if ($tenant) {
            $this->audit->record('residency-blocked', $tenant, null, [
                'layer' => $layer,
                'target' => $target,
                'target_region' => $region,
                'declared_country' => $declared,
            ]);
        } else {
            Log::warning('Residency guard block outside tenant scope', [
                'layer' => $layer, 'target' => $target,
                'target_region' => $region, 'declared_country' => $declared,
            ]);
        }

        throw new ResidencyViolationException(
            "Residency guard: {$layer} target '{$target}' sits in {$region}, outside the declared country {$declared}."
        );
    }
}
