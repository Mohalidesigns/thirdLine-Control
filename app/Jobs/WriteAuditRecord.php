<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single write path into audit_trails (CR3). Queued so the business
 * request never waits on (or fails because of) the log write; dispatched
 * afterCommit so a rolled-back transaction never leaves a phantom audit row.
 *
 * Each append extends the tamper-evidence hash chain:
 * row_hash = sha256(previous_hash . canonical(payload)). audit_chain_head
 * is locked FOR UPDATE for the duration of the insert, which serialises
 * concurrent appends without gap-locking the big table itself.
 * `audit:verify-chain` walks the chain and recomputes every link.
 */
class WriteAuditRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    /**
     * Fields included in the row hash, in canonical order. Anything not
     * listed (the id, the hash columns themselves) is excluded by design.
     */
    public const HASHED_FIELDS = [
        'tenant_id', 'user_id', 'actor_name', 'actor_email',
        'entity_type', 'entity_id', 'action', 'event_label',
        'subject_label', 'description', 'before', 'after',
        'ip_address', 'user_agent', 'method', 'url', 'route_name',
        'status_code', 'device_name', 'batch_id', 'created_at',
    ];

    /**
     * @param  array<string, mixed>  $row  Column values captured at event
     *                                     time; before/after as arrays.
     */
    public function __construct(public array $row) {}

    public function handle(): void
    {
        $row = $this->row;

        DB::transaction(function () use ($row) {
            $head = DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();

            $previous = $head?->last_hash ?? str_repeat('0', 64);

            $row['previous_hash'] = $previous;
            $row['row_hash'] = self::hashRow($row, $previous);

            $insert = $row;
            foreach (['before', 'after'] as $json) {
                if (isset($insert[$json]) && is_array($insert[$json])) {
                    $insert[$json] = json_encode($insert[$json]);
                }
            }

            $id = DB::table('audit_trails')->insertGetId($insert);

            DB::table('audit_chain_head')->where('id', 1)->update([
                'last_hash' => $row['row_hash'],
                'last_audit_id' => $id,
            ]);
        });
    }

    /**
     * Canonical, storage-independent hash of a row. JSON payloads are
     * recursively key-sorted before encoding so MySQL's JSON-column key
     * normalisation cannot break verification.
     *
     * @param  array<string, mixed>  $row
     */
    public static function hashRow(array $row, string $previousHash): string
    {
        $canonical = [];
        foreach (self::HASHED_FIELDS as $field) {
            $value = $row[$field] ?? null;
            if (is_string($value) && in_array($field, ['before', 'after'], true)) {
                $value = json_decode($value, true);
            }
            if (is_array($value)) {
                $value = json_encode(self::ksortDeep($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $canonical[$field] = $value === null ? null : (string) $value;
        }

        return hash('sha256', $previousHash.json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function ksortDeep(array $value): array
    {
        ksort($value);

        return array_map(
            fn ($v) => is_array($v) ? self::ksortDeep($v) : $v,
            $value,
        );
    }

    /**
     * An audit write that exhausts its retries is an evidence gap — say so
     * loudly, with the payload, so the row can be reconstructed by hand.
     */
    public function failed(?\Throwable $e): void
    {
        Log::critical('Audit trail write permanently failed — evidence gap', [
            'row' => $this->row,
            'error' => $e?->getMessage(),
        ]);
    }
}
