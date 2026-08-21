<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AuditTrailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for the activity log itself (CR3): rows older than the
 * retention window are moved to cold storage — gzipped JSONL per tenant
 * per calendar month on the archive disk — never simply deleted. Every
 * file is written and row-count-verified BEFORE the originals are
 * removed, the chain boundary hash is preserved in a manifest so
 * `audit:verify-chain` anchors remain externally checkable, and the
 * archival run itself is logged as an event.
 *
 * The window is config('audit.retention_months'); a tenant may set a
 * LONGER window via settings['audit_retention_months'] (Security Policy
 * page). A tenant override can extend retention but never shorten it
 * below the configured default — the log is evidence, not disk to free.
 *
 * The immutability triggers (DEF-004) rightly block DELETE, so the
 * command drops them for the shortest possible window and reinstalls
 * them in a finally block.
 */
class ArchiveAuditTrail extends Command
{
    protected $signature = 'audit:archive
        {--months= : Override the retention window in months}
        {--dry-run : Report what would be archived without touching anything}';

    protected $description = 'Move activity-log rows older than the retention window to cold storage';

    public function handle(): int
    {
        $defaultMonths = (int) ($this->option('months') ?: config('audit.retention_months'));

        if ($defaultMonths < 12) {
            $this->error('Refusing a retention window under 12 months — the activity log is evidence.');

            return self::FAILURE;
        }

        $archivedTotal = 0;

        // Per-tenant windows: an override may extend, never shorten.
        // Null-tenant rows (system events) use the default window.
        $scopes = Tenant::query()->get()
            ->map(fn ($t) => [
                'tenant_id' => $t->id,
                'months' => max($defaultMonths, (int) ($t->settings['audit_retention_months'] ?? 0)),
            ])
            ->push(['tenant_id' => null, 'months' => $defaultMonths]);

        foreach ($scopes as $scope) {
            $archivedTotal += $this->archiveScope($scope['tenant_id'], $scope['months']);
        }

        if ($archivedTotal > 0 && ! $this->option('dry-run')) {
            app(AuditTrailService::class)->recordEvent(
                'audit_log_archived',
                'system',
                "Archived {$archivedTotal} activity-log rows to cold storage",
                ['rows' => $archivedTotal, 'default_months' => $defaultMonths],
            );
        }

        $this->info($this->option('dry-run')
            ? "Would archive {$archivedTotal} rows."
            : "Archived {$archivedTotal} rows.");

        return self::SUCCESS;
    }

    private function archiveScope(?int $tenantId, int $months): int
    {
        $cutoff = now()->subMonths($months)->startOfDay();
        $disk = Storage::disk(config('audit.archive.disk'));
        $basePath = config('audit.archive.path');
        $prefix = $tenantId === null ? 'system' : "tenant-{$tenantId}";

        $query = DB::table('audit_trails')
            ->when(
                $tenantId === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            return (clone $query)->count();
        }

        $ymExpression = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $monthsPresent = (clone $query)
            ->selectRaw("{$ymExpression} as ym")
            ->distinct()->orderBy('ym')->pluck('ym');

        $archived = 0;

        foreach ($monthsPresent as $ym) {
            $rows = (clone $query)
                ->whereRaw("{$ymExpression} = ?", [$ym])
                ->orderBy('id')->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $file = "{$basePath}/{$prefix}/audit-trails-{$ym}.jsonl.gz";
            $disk->put($file, gzencode($rows->map(fn ($r) => json_encode($r))->implode("\n")."\n", 9));

            // Verify the export is complete and readable before deleting.
            $written = substr_count((string) gzdecode((string) $disk->get($file)), "\n");
            if ($written !== $rows->count()) {
                $this->error("Export verification failed for {$prefix} {$ym} ({$written} of {$rows->count()} rows) — stopping before any deletion.");

                return $archived;
            }

            $disk->put("{$basePath}/{$prefix}/audit-trails-{$ym}.manifest.json", json_encode([
                'tenant_id' => $tenantId,
                'month' => $ym,
                'rows' => $rows->count(),
                'first_id' => $rows->first()->id,
                'last_id' => $rows->last()->id,
                'last_row_hash' => $rows->last()->row_hash,
                'archived_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));

            $ids = $rows->pluck('id');

            // Shortest possible unprotected window; triggers always restored.
            InstallAuditTriggers::drop();
            try {
                $ids->chunk(500)->each(
                    fn ($chunk) => DB::table('audit_trails')->whereIn('id', $chunk->all())->delete()
                );
            } finally {
                InstallAuditTriggers::install();
            }

            $archived += $rows->count();
            $this->line("Archived {$rows->count()} rows for {$prefix} {$ym} → {$file}");
        }

        return $archived;
    }
}
