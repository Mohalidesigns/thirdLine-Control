<?php

namespace Database\Seeders;

use App\Models\ControlEntity;
use App\Models\ControlFunctionImport;
use App\Models\ControlUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ControlFunctionImportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * CR-03 §D.3: the client's workbook, installed through the SAME code
 * path the UI import uses. A seeder that reimplemented the parse would
 * drift from the importer the first time either changed, and the bank's
 * revision-2 workbook would then land differently from the one we seeded.
 *
 * Idempotent by the importer's natural keys: a second run reports zero
 * changes and writes nothing.
 */
class ControlFunctionChecklistSeeder extends Seeder
{
    public const VERSION = '1.0.0';

    public function run(): void
    {
        $service = app(ControlFunctionImportService::class);

        try {
            $pack = $service->loadPack(self::VERSION);
        } catch (\RuntimeException $e) {
            $this->command?->warn('Control function content pack not available — skipping. '.$e->getMessage());

            return;
        }

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $this->seedTenant($service, $pack, (int) $tenantId);
        }

        $this->seedDemoOfficers();
    }

    /**
     * §G.8 is a live question for the client — the six head office desks
     * need named control officers before generation, or every head office
     * task lands unassigned. For the DEMO tenant we answer it here, so the
     * shipped dataset is navigable rather than 316 ownerless tasks.
     *
     * This touches the demo tenant only. A real tenant names its own
     * officers, and the unassigned notification exists precisely to make
     * the gap visible until it does.
     */
    private function seedDemoOfficers(): void
    {
        $tenant = Tenant::query()->where('name', 'Demo Bank Plc')->first();

        if (! $tenant) {
            return;
        }

        $head = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Control Function Head'))
            ->first();

        $officers = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Control Officer'))
            ->orderBy('id')
            ->get();

        if ($officers->isEmpty()) {
            return;
        }

        foreach (ControlUnit::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() as $unit) {
            if ($head && ! $unit->head_user_id) {
                $unit->forceFill(['head_user_id' => $head->id])->save();
            }
        }

        // Round-robin: a demo bank with one officer on every desk reads as
        // a bank with one officer, which is not what the screens are for.
        $entities = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('entity_kind', ['department', 'branch'])
            ->whereNull('default_officer_id')
            ->orderBy('id')
            ->get();

        foreach ($entities as $index => $entity) {
            $entity->forceFill([
                'default_officer_id' => $officers[$index % $officers->count()]->id,
            ])->save();
        }
    }

    private function seedTenant(ControlFunctionImportService $service, array $pack, int $tenantId): void
    {
        // A committed run of the same file is the idempotency guard that
        // costs nothing: 1,517 rows staged twice is 1,517 rows of noise.
        $already = ControlFunctionImport::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_hash', $pack['source_sha256'] ?? '')
            ->where('status', 'Committed')
            ->exists();

        if ($already) {
            return;
        }

        try {
            $import = $service->import(
                $pack['rows'],
                [
                    'source_name' => $pack['source_file'] ?? $pack['name'],
                    'source_hash' => $pack['source_sha256'] ?? null,
                    'source_version' => $pack['version'] ?? self::VERSION,
                ],
                $tenantId,
            );

            $this->command?->info(sprintf(
                'Control functions for tenant %d: %d added, %d changed, %d scripts versioned.',
                $tenantId, $import->controls_added, $import->controls_changed, $import->scripts_versioned,
            ));
        } catch (\Throwable $e) {
            // A tenant without the CR-02 control structure seeded has no
            // HOC/BRC sub-units to hang functions on. That is a sequencing
            // problem, not a reason to fail the whole seed run.
            Log::warning('Control function checklist seed skipped', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            $this->command?->warn(sprintf('Tenant %d skipped: %s', $tenantId, $e->getMessage()));
        }
    }
}
