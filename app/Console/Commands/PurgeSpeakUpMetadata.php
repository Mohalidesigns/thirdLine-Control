<?php

namespace App\Console\Commands;

use App\Services\SpeakUpMetadataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CR — scheduled hard-delete of expired Speak Up reporter metadata.
 * Retention runs from case closure; a case under legal hold is skipped;
 * the report and its case history are untouched. Every deletion lands in
 * the Metadata Access Log and the case's audit trail.
 */
class PurgeSpeakUpMetadata extends Command
{
    protected $signature = 'speak-up:purge-metadata';

    protected $description = 'Hard-delete Speak Up reporter metadata past its retention window';

    public function handle(SpeakUpMetadataService $metadata): int
    {
        $purged = $metadata->purgeExpired();

        Log::info('Speak Up metadata purge completed', ['rows_purged' => $purged]);
        $this->info("Purged {$purged} expired metadata row(s).");

        return self::SUCCESS;
    }
}
