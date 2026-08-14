<?php

namespace App\Console\Commands;

use App\Services\VendorService;
use Illuminate\Console\Command;

class RefreshVendorRisk extends Command
{
    protected $signature = 'atheris:refresh-vendor-risk {--tenant= : Limit to one tenant id}';

    protected $description = 'Expire lapsed third-party due-diligence reviews, raise the review obligation, and move contracts into their renewal-notice window';

    public function handle(VendorService $vendors): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $reviews = $vendors->expireAssessments($tenantId);
        $contracts = $vendors->refreshContracts($tenantId);

        $this->info(sprintf(
            '%d due-diligence review(s) expired, %d exception(s) raised; %d contract(s) entered their notice window, %d expired.',
            $reviews['expired'],
            $reviews['raised'],
            $contracts['alerted'],
            $contracts['expired'],
        ));

        return self::SUCCESS;
    }
}
