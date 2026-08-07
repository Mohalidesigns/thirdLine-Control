<?php

namespace App\Console\Commands;

use App\Services\EvidenceService;
use Illuminate\Console\Command;

class QueueEvidenceDisposal extends Command
{
    protected $signature = 'secondline:queue-evidence-disposal';

    protected $description = 'Flag evidence past its retention expiry for the dual-approval disposal workflow (FR-9.8); legal holds are never queued';

    public function handle(EvidenceService $evidenceService): int
    {
        $queued = $evidenceService->queueExpiredForDisposal();

        $this->info("Queued {$queued} evidence item(s) for disposal approval.");

        return self::SUCCESS;
    }
}
