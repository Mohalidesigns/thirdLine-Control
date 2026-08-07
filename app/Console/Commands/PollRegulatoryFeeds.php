<?php

namespace App\Console\Commands;

use App\Services\RegulatoryChangeService;
use Illuminate\Console\Command;

class PollRegulatoryFeeds extends Command
{
    protected $signature = 'atheris:poll-regulatory-feeds {--tenant= : Restrict to a single tenant id}';

    protected $description = 'Poll regulator publication feeds and log new circulars as regulatory changes awaiting impact assessment';

    public function handle(RegulatoryChangeService $changes): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $ingested = $changes->ingestFromFeeds($tenantId);

        $this->info("Ingested {$ingested} new regulatory publication(s).");

        return self::SUCCESS;
    }
}
