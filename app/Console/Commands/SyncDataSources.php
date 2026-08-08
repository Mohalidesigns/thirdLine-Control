<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Services\ConnectorManager;
use App\Services\SnapshotService;
use Illuminate\Console\Command;

/**
 * Pull every active dataset on every approved, unpaused source (12.5).
 *
 * The command is a loop and nothing more: the circuit breaker, the rate
 * limit, the retention date and the PII treatment all live in the
 * services, so running this by hand behaves exactly like the scheduler.
 */
class SyncDataSources extends Command
{
    protected $signature = 'atheris:sync-data-sources
        {--tenant= : Limit to one tenant id}
        {--source= : Limit to one data source id}';

    protected $description = 'Extract every active dataset from every approved data source into a snapshot';

    public function handle(SnapshotService $snapshots, ConnectorManager $connectors): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $sourceId = $this->option('source') ? (int) $this->option('source') : null;

        $sources = $snapshots->dueSources($tenantId)
            ->when($sourceId, fn ($collection) => $collection->where('id', $sourceId));

        $captured = 0;
        $rows = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            /** @var DataSource $source */
            if ($source->datasets->isEmpty()) {
                $skipped++;

                continue;
            }

            foreach ($source->datasets as $dataset) {
                try {
                    $snapshot = $snapshots->capture($dataset);
                    $captured++;
                    $rows += (int) $snapshot->row_count;

                    $this->line("  <info>✓</info> {$source->name} / {$dataset->name} — "
                        ."{$snapshot->row_count} row(s) as {$snapshot->snapshot_ref}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->line("  <fg=red>✗</> {$source->name} / {$dataset->name} — ".$e->getMessage());

                    // The breaker may have tripped on this failure; if it
                    // has, the remaining datasets on this source are moot.
                    if ($source->fresh()->paused_at !== null) {
                        $this->warn("    {$source->name} paused by the circuit breaker — skipping its remaining datasets.");
                        break;
                    }
                }
            }
        }

        $this->info(sprintf(
            '%d snapshot(s) captured, %s row(s) ingested, %d failure(s), %d source(s) with no active dataset.',
            $captured, number_format($rows), $failed, $skipped,
        ));

        return self::SUCCESS;
    }
}
