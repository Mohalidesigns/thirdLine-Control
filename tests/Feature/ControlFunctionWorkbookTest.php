<?php

namespace Tests\Feature;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlUnit;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\TestScript;
use App\Services\ControlFunctionImportService;
use Database\Seeders\ControlFrequencySeeder;
use Database\Seeders\ControlFunctionChecklistSeeder;
use Database\Seeders\ControlStructureSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CR-03 §F Phase 3, done-when: importing the client's workbook produces
 * exactly 167 controls, 167 test scripts, 1,517 check items and 0
 * unresolved rows; re-importing the same file reports zero changes.
 *
 * This runs against the committed content pack — the JSON extract of
 * `ATHERIS_ Departmental Control Function Checklists.xlsx` — so the
 * numbers below are the bank's own document, not a fixture we invented.
 */
class ControlFunctionWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);
        $this->seed(ControlFrequencySeeder::class);

        $this->tenant = Tenant::create(['name' => 'Workbook Bank', 'status' => 'active', 'data_residency' => 'NG']);

        OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'HO', 'name' => 'Head Office', 'type' => 'Head Office',
        ]);

        $this->seed(ControlStructureSeeder::class);
    }

    public function test_the_clients_workbook_imports_whole(): void
    {
        $this->seed(ControlFunctionChecklistSeeder::class);

        $functions = Control::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('is_control_function', true);

        $this->assertSame(167, (clone $functions)->count(), 'The workbook holds 167 control functions.');

        $scripts = TestScript::withoutGlobalScopes()
            ->whereIn('control_id', (clone $functions)->pluck('id'));

        $this->assertSame(167, (clone $scripts)->count(), 'One checklist per function.');

        $items = CheckItem::query()->whereIn('test_script_id', (clone $scripts)->pluck('id'));

        $this->assertSame(1517, (clone $items)->count(), 'The workbook holds 1,517 checklist lines.');

        // Six head office desks, created from the Units column.
        $hoc = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('code', 'HOC')->firstOrFail();

        $desks = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('control_unit_id', $hoc->id)
            ->where('is_import_created', true)
            ->pluck('name');

        $this->assertCount(6, $desks);
        $this->assertContains('HEAD OFFICE HCM/VFM CONTROL', $desks->all());
        $this->assertContains('NOSTRO Accounts Reconciliation', $desks->all());

        // The branch sheet is ONE template unit, not a desk per branch.
        $brc = ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('code', 'BRC')->firstOrFail();

        $branchFunctions = Control::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('control_unit_id', $brc->id)
            ->where('is_control_function', true)
            ->count();

        $this->assertSame(73, $branchFunctions, 'The branch checklist is 73 functions held once.');
    }

    public function test_every_frequency_in_the_workbook_resolves(): void
    {
        $service = app(ControlFunctionImportService::class);
        $pack = $service->loadPack();

        $import = $service->dryRun(
            $pack['rows'],
            ['source_name' => $pack['source_file'], 'source_hash' => $pack['source_sha256']],
            $this->tenant->id,
        );

        $this->assertSame(1517, $import->rows_total);
        $this->assertSame(
            0,
            $import->rows_unresolved,
            'Every one of the thirteen spellings in the workbook must map to a frequency.',
        );
    }

    public function test_the_seven_mixed_rhythm_functions_carry_line_level_overrides(): void
    {
        $this->seed(ControlFunctionChecklistSeeder::class);

        // NOSTRO is the clearest case: eleven daily lines and five
        // monthly, one control, one checklist (§A.4).
        $nostro = Control::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereRaw('lower(title) = ?', ['nostro'])
            ->firstOrFail();

        $items = $nostro->activeTestScript()->checkItems()->with('controlFrequency')->get();

        $this->assertCount(16, $items);
        $this->assertSame('daily', $nostro->resolvedFrequency()->code, 'Eleven daily lines dominate.');

        $overrides = $items->whereNotNull('frequency_id');
        $this->assertCount(5, $overrides);
        $this->assertTrue($overrides->every(fn ($item) => $item->controlFrequency->code === 'monthly'));
    }

    public function test_a_repeat_seed_is_a_no_op(): void
    {
        $this->seed(ControlFunctionChecklistSeeder::class);

        $before = [
            'controls' => Control::withoutGlobalScopes()->count(),
            'scripts' => TestScript::withoutGlobalScopes()->count(),
            'items' => CheckItem::query()->count(),
        ];

        $this->seed(ControlFunctionChecklistSeeder::class);

        $this->assertSame($before['controls'], Control::withoutGlobalScopes()->count());
        $this->assertSame($before['scripts'], TestScript::withoutGlobalScopes()->count());
        $this->assertSame($before['items'], CheckItem::query()->count());
    }
}
