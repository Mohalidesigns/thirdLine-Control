<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ControlStructureService;
use Illuminate\Console\Command;

/**
 * CR2A.2 — branch auto-provisioning. Idempotent and add-only: a second
 * run creates nothing; a template activity added later reaches existing
 * branches on the next run; deleting or editing a template never deletes
 * or rewrites instantiated rows.
 */
class SyncControlStructureBranches extends Command
{
    protected $signature = 'control-structure:sync-branches';

    protected $description = 'Ensure every Branch organisation unit has a control entity and its template activities under Branch Control';

    public function handle(ControlStructureService $service): int
    {
        $totalBranches = 0;
        $totalActivities = 0;

        // Iterate tenants explicitly, the way EscalationService does —
        // the command runs unauthenticated, so the tenant global scope
        // resolves to nothing.
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $created = $service->syncBranches($tenantId);
            $totalBranches += $created['branches'];
            $totalActivities += $created['activities'];
        }

        $this->info("Provisioned {$totalBranches} branch entit(ies) and {$totalActivities} activit(ies).");

        return self::SUCCESS;
    }
}
