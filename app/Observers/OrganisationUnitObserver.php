<?php

namespace App\Observers;

use App\Models\OrganisationUnit;
use App\Services\ControlStructureService;
use Illuminate\Support\Facades\Log;

/**
 * CR2A.2: a branch opened in Kano on Monday appears under Branch Control
 * on Monday — a newly created Branch unit is provisioned immediately,
 * without waiting for the nightly sync. Provisioning failure must never
 * block the organisational change itself.
 */
class OrganisationUnitObserver
{
    public function created(OrganisationUnit $unit): void
    {
        if ($unit->type !== 'Branch') {
            return;
        }

        try {
            app(ControlStructureService::class)->provisionBranch($unit);
        } catch (\Throwable $e) {
            Log::warning('Branch control-entity provisioning failed — the nightly sync will retry.', [
                'organisation_unit_id' => $unit->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
