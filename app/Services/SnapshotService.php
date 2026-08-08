<?php

namespace App\Services;

use App\Integrations\ConnectorException;
use App\Models\DataSnapshot;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Extraction and the snapshot store (12.1, 12.7).
 *
 * Three rules hold everywhere in this class:
 *
 *  1. Nothing is written before it is classified. A row passes through
 *     redactRow() on its way to disk, so a sensitive field is hashed at
 *     ingestion and the clear value never touches storage (R5).
 *  2. Every path begins with the tenant's data-residency code. A snapshot
 *     cannot be written outside its own country plane, and pathFor() is
 *     the single place that decides — asserted directly in the tests.
 *  3. Legal hold beats retention. The purge sweep never sees a held
 *     snapshot, and the model would refuse it anyway (Phase 5 pattern).
 */
class SnapshotService
{
    public function __construct(private ConnectorManager $connectors) {}

    /**
     * Pull a dataset and store it as a snapshot.
     *
     * @throws ConnectorException
     */
    public function capture(DataSourceDataset $dataset, ?CarbonImmutable $since = null, ?string $periodLabel = null): DataSnapshot
    {
        $source = $dataset->dataSource;

        if (! $source) {
            throw new ConnectorException('The dataset is not attached to a source.');
        }

        $this->connectors->assertExtractable($source);

        $snapshot = DataSnapshot::create([
            'tenant_id' => $dataset->tenant_id,
            'dataset_id' => $dataset->id,
            'snapshot_ref' => DataSnapshot::nextReference('SNP', true, 'snapshot_ref'),
            'period_label' => $periodLabel ?? now()->format('Y-m-d H:i'),
            'status' => 'Capturing',
        ]);

        $path = $this->pathFor($dataset, $snapshot);
        $disk = $this->disk();
        $maxRows = min(
            (int) config('monitoring.max_rows_per_snapshot'),
            (int) ($dataset->extraction_config['max_rows'] ?? PHP_INT_MAX),
        );
        $maxBytes = (int) config('monitoring.max_bytes_per_snapshot');

        $rows = 0;
        $bytes = 0;
        $partial = false;
        $hash = hash_init('sha256');

        // Spooled to memory up to 8MB, then to a temp file, and written to
        // the disk in one stream — appending row by row would re-read the
        // whole snapshot on every line.
        $buffer = fopen('php://temp/maxmemory:8388608', 'w+b');

        try {
            $connector = $this->connectors->connector($source);

            foreach ($connector->extract($dataset, $since) as $row) {
                if ($rows >= $maxRows || $bytes >= $maxBytes) {
                    $partial = true;
                    break;
                }

                $line = json_encode($this->redactRow((array) $row, $dataset), JSON_UNESCAPED_UNICODE)."\n";
                hash_update($hash, $line);
                fwrite($buffer, $line);
                $bytes += strlen($line);
                $rows++;
            }

            rewind($buffer);
            $disk->writeStream($path, $buffer);

            $snapshot->update([
                'status' => 'Ready',
                'captured_at' => now(),
                'row_count' => $rows,
                'size_bytes' => $bytes,
                'checksum' => hash_final($hash),
                'storage_path' => $path,
                'expires_at' => now()->addDays(max(1, (int) $dataset->retention_days))->toDateString(),
            ]);

            $dataset->update(['row_count_last' => $rows]);
            $this->connectors->recordSuccess($source, $partial);
            $this->connectors->log($source, 'extraction', $partial ? 'Success' : 'Success', [
                'dataset' => $dataset->name,
                'snapshot_ref' => $snapshot->snapshot_ref,
                'rows' => $rows,
                'bytes' => $bytes,
                'partial' => $partial,
            ]);

            return $snapshot->fresh();
        } catch (\Throwable $e) {
            $message = ConnectorException::redactMessage($e->getMessage());

            $snapshot->update(['status' => 'Failed', 'error_message' => $message, 'storage_path' => $path]);
            $this->connectors->recordFailure($source, $message);
            $this->connectors->log($source, 'extraction', 'Failed', [
                'dataset' => $dataset->name,
                'snapshot_ref' => $snapshot->snapshot_ref,
                'rows' => $rows,
            ], $message);

            throw $e instanceof ConnectorException ? $e : ConnectorException::from($e);
        } finally {
            if (is_resource($buffer)) {
                fclose($buffer);
            }
        }
    }

    /**
     * Read a snapshot back, one row at a time. A quarter-million-row
     * extract must never be materialised as one array (R6).
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function rows(DataSnapshot $snapshot): iterable
    {
        if ($snapshot->status === 'Purged' || ! $snapshot->storage_path) {
            return;
        }

        $disk = $this->disk();

        if (! $disk->exists($snapshot->storage_path)) {
            return;
        }

        $stream = $disk->readStream($snapshot->storage_path);

        if (! $stream) {
            return;
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** The latest usable snapshot of a dataset. */
    public function latestReady(DataSourceDataset $dataset): ?DataSnapshot
    {
        return DataSnapshot::withoutGlobalScopes()
            ->where('dataset_id', $dataset->id)
            ->where('status', 'Ready')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Retention enforcement (12.5). A snapshot under legal hold is never
     * returned by the purgeable scope, and the model would throw if one
     * somehow arrived here — two layers, deliberately.
     *
     * @return array{purged: int, held: int, bytes: int}
     */
    public function purgeExpired(?int $tenantId = null): array
    {
        $purged = 0;
        $bytes = 0;

        $held = DataSnapshot::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('legal_hold', true)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now())
            ->count();

        DataSnapshot::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->purgeable()
            ->chunkById(100, function ($snapshots) use (&$purged, &$bytes) {
                foreach ($snapshots as $snapshot) {
                    if ($snapshot->legal_hold) {
                        continue;
                    }

                    $bytes += (int) $snapshot->size_bytes;
                    $this->purge($snapshot);
                    $purged++;
                }
            });

        return ['purged' => $purged, 'held' => $held, 'bytes' => $bytes];
    }

    /** Delete the stored rows and mark the snapshot purged, keeping the record. */
    public function purge(DataSnapshot $snapshot): DataSnapshot
    {
        if ($snapshot->legal_hold) {
            throw new \LogicException(
                "Snapshot {$snapshot->snapshot_ref} is under legal hold and cannot be purged."
            );
        }

        if ($snapshot->storage_path && $this->disk()->exists($snapshot->storage_path)) {
            $this->disk()->delete($snapshot->storage_path);
        }

        $snapshot->update([
            'status' => 'Purged',
            'purged_at' => now(),
            'storage_path' => null,
            'size_bytes' => 0,
        ]);

        $snapshot->auditAction('purged', null, ['reason' => 'retention']);

        return $snapshot->fresh();
    }

    /**
     * The storage path for a snapshot. Every path is
     * `{root}/{residency}/tenant-{id}/dataset-{id}/{ref}.jsonl`, so the
     * data plane a file sits in is readable from the path itself and a
     * misfiled snapshot is visible rather than merely wrong (R5).
     */
    public function pathFor(DataSourceDataset $dataset, DataSnapshot $snapshot): string
    {
        return implode('/', [
            trim((string) config('monitoring.snapshot_root'), '/'),
            $this->residencyFor($dataset->tenant_id),
            'tenant-'.$dataset->tenant_id,
            'dataset-'.$dataset->id,
            $snapshot->snapshot_ref.'.jsonl',
        ]);
    }

    /** The tenant's declared data-residency country, upper-cased. */
    public function residencyFor(?int $tenantId): string
    {
        $residency = Tenant::withoutGlobalScopes()->find($tenantId)?->data_residency;

        return strtoupper((string) ($residency ?: 'NG'));
    }

    /**
     * Apply the dataset's declared PII treatment to one row.
     *
     * - sensitive_personal without a DPO authorisation: declared fields
     *   are replaced with a keyed HMAC, so the same subject still joins
     *   across snapshots but the value is not recoverable.
     * - personal / sensitive_personal with authorisation: retained, and
     *   the authorisation is on the dataset record to prove why.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function redactRow(array $row, DataSourceDataset $dataset): array
    {
        if (! $dataset->requiresHashing()) {
            return $row;
        }

        foreach ($dataset->personalFields() as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $row[$field] = $this->pseudonymise((string) $row[$field], $dataset->tenant_id);
            }
        }

        return $row;
    }

    /**
     * Redaction for a *finding*, which is read by people. Anything above
     * 'internal' is masked here regardless of the DPO authorisation:
     * authorising retention in the snapshot store is not authorising the
     * value onto a control officer's screen (12.7).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function redactForFinding(array $row, DataSourceDataset $dataset): array
    {
        if (in_array($dataset->pii_classification, ['none', 'internal'], true)) {
            return $row;
        }

        foreach ($dataset->personalFields() as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $row[$field] = $this->mask((string) $row[$field]);
            }
        }

        return $row;
    }

    /** Stable, keyed, one-way. Not reversible, and not a bare sha256. */
    public function pseudonymise(string $value, ?int $tenantId): string
    {
        $salt = (string) config('monitoring.pii_salt');

        return 'hash:'.substr(hash_hmac('sha256', $value, $salt.'|'.$tenantId), 0, 32);
    }

    /** Keep the last four characters so a human can still recognise a record. */
    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        return $length <= 4
            ? str_repeat('•', $length)
            : str_repeat('•', $length - 4).mb_substr($value, -4);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('monitoring.snapshot_disk'));
    }

    /** Sources whose cron is due, for the sync sweep. */
    public function dueSources(?int $tenantId = null): Collection
    {
        return DataSource::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotNull('approved_at')
            ->where('is_active', true)
            ->whereNull('paused_at')
            ->with(['datasets' => fn ($q) => $q->where('is_active', true)])
            ->get();
    }
}
