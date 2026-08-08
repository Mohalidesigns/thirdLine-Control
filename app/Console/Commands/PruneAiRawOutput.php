<?php

namespace App\Console\Commands;

use App\Models\AiInteraction;
use Illuminate\Console\Command;

/**
 * Clears raw model output past its retention window.
 *
 * The interaction row itself is never deleted — the AI audit log is
 * append-only (R3), and who asked what, which prompt version ran, what it
 * cost and what a human decided are the facts a governance review needs.
 * What ages out is only the verbatim model text, which is the bulkiest part
 * and the part most likely to carry incidental narrative detail.
 *
 * parsed_output stays: it is the structured draft a person acted on, and
 * removing it would leave an accepted decision with no record of what was
 * accepted.
 */
class PruneAiRawOutput extends Command
{
    protected $signature = 'ai:prune {--days= : Override the configured retention window}';

    protected $description = 'Clear verbatim AI model output past its retention window, keeping the interaction record';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('ai.output.raw_output_retention_days', 180));

        if ($days <= 0) {
            $this->info('Raw output retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $cleared = AiInteraction::withoutGlobalScopes()
            ->whereNotNull('raw_output')
            ->where('created_at', '<', now()->subDays($days))
            ->update(['raw_output' => null]);

        $this->info("Cleared raw output on {$cleared} interaction(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
