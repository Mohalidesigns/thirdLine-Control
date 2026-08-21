<?php

namespace App\Console\Commands;

use App\Jobs\WriteAuditRecord;
use App\Models\AuditTrail;
use Illuminate\Console\Command;

/**
 * Walks the audit_trails hash chain and recomputes every link (CR3).
 * Rows written before the chain existed (row_hash NULL) are reported but
 * not treated as failures; the first hashed row after an archival cut is
 * accepted as the chain anchor (its boundary hash is preserved in the
 * archive manifest for external validation).
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain {--from= : Verify from this audit row id}';

    protected $description = 'Verify the tamper-evidence hash chain on the activity log';

    public function handle(): int
    {
        $checked = 0;
        $unhashed = 0;
        $broken = [];
        $previous = null; // null = anchor not yet established

        AuditTrail::query()
            ->when($this->option('from'), fn ($q, $from) => $q->where('id', '>=', (int) $from))
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$checked, &$unhashed, &$broken, &$previous) {
                foreach ($rows as $row) {
                    if ($row->row_hash === null) {
                        $unhashed++;

                        continue;
                    }

                    // Linkage: every hashed row after the first must chain
                    // off the previous hashed row.
                    if ($previous !== null && $row->previous_hash !== $previous) {
                        $broken[] = [$row->id, 'previous_hash does not chain'];
                    }

                    // Integrity: the row's own content must still produce
                    // its stored hash.
                    $recomputed = WriteAuditRecord::hashRow(
                        $row->getAttributes(),
                        $row->previous_hash,
                    );

                    if ($recomputed !== $row->row_hash) {
                        $broken[] = [$row->id, 'row content does not match its hash'];
                    }

                    $previous = $row->row_hash;
                    $checked++;
                }
            });

        $this->info("Checked {$checked} hashed rows".($unhashed ? " ({$unhashed} pre-chain rows skipped)" : '').'.');

        if ($broken !== []) {
            $this->error(count($broken).' CHAIN FAILURE(S) — the log may have been tampered with:');
            $this->table(['Row id', 'Failure'], $broken);

            return self::FAILURE;
        }

        $this->info('Hash chain intact.');

        return self::SUCCESS;
    }
}
