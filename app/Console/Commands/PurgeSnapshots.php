<?php

namespace App\Console\Commands;

use App\Models\DataSnapshot;
use App\Services\SnapshotService;
use Illuminate\Console\Command;

/**
 * Retention enforcement for snapshot data (12.5, 12.7).
 *
 * Snapshots past their dataset's retention_days are deleted from storage
 * and the record marked Purged — the row survives so the audit trail can
 * still show that a run read data that no longer exists.
 *
 * A snapshot under legal hold is never purged. The command reports how
 * many it left alone: silence would read as "nothing was due".
 */
class PurgeSnapshots extends Command
{
    protected $signature = 'atheris:purge-snapshots
        {--tenant= : Limit to one tenant id}
        {--dry-run : Report what would be purged without deleting anything}';

    protected $description = 'Purge expired data snapshots, excluding anything under legal hold';

    public function handle(SnapshotService $snapshots): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        if ($this->option('dry-run')) {
            $due = DataSnapshot::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->purgeable()
                ->get(['snapshot_ref', 'row_count', 'size_bytes', 'expires_at']);

            foreach ($due as $snapshot) {
                $this->line(sprintf(
                    '  %s — %s row(s), %s KB, expired %s',
                    $snapshot->snapshot_ref,
                    number_format($snapshot->row_count),
                    number_format($snapshot->size_bytes / 1024, 1),
                    $snapshot->expires_at?->toDateString(),
                ));
            }

            $this->info($due->count().' snapshot(s) would be purged. Nothing was deleted.');

            return self::SUCCESS;
        }

        $result = $snapshots->purgeExpired($tenantId);

        $this->info(sprintf(
            '%d snapshot(s) purged, %s KB reclaimed.',
            $result['purged'],
            number_format($result['bytes'] / 1024, 1),
        ));

        // Reported on its own line, always. Silence here would read as
        // "nothing was due" when the truth is "something was due and the
        // law says it stays".
        $this->line(sprintf(
            '%d expired snapshot(s) retained under legal hold.',
            $result['held'],
        ));

        return self::SUCCESS;
    }
}
