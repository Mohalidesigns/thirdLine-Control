<?php

namespace Tests\Feature;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlFrequency;
use App\Models\ControlFunctionImport;
use App\Models\ControlUnit;
use App\Models\FeatureFlag;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use App\Services\ControlFunctionExportService;
use App\Services\ControlFunctionImportService;
use App\Services\ControlTaskService;
use App\Services\FeatureService;
use App\Services\FrequencyResolver;
use App\Services\MyWorkService;
use App\Services\TestingService;
use Carbon\CarbonImmutable;
use Database\Seeders\ControlFrequencySeeder;
use Database\Seeders\ControlStructureSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CR-03 — departmental control function checklists and the frequency
 * engine. The four gaps the change request identified, each with the
 * test that proves it closed:
 *
 *  Gap 1  frequency vocabulary too narrow  → the alias and catalogue tests
 *  Gap 2  frequency only at function level → the mixed-rhythm tests
 *  Gap 3  instances cannot be branch-scoped → the scoping tests
 *  Gap 4  no import path from the workbook → the importer tests
 */
class ControlFunctionChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $admin;

    private ControlUnit $hoc;

    private ControlUnit $brc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);
        $this->seed(ControlFrequencySeeder::class);

        $this->tenant = Tenant::create(['name' => 'Checklist Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@checklist.test', 'Control Function Head');
        $this->officer = $this->makeUser('officer@checklist.test', 'Control Officer');
        $this->admin = $this->makeUser('admin@checklist.test', 'System Administrator');

        $head = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'HO', 'name' => 'Head Office', 'type' => 'Head Office',
        ]);
        OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'BR-001', 'name' => 'Marina Branch',
            'type' => 'Branch', 'parent_id' => $head->id,
        ]);
        OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'BR-002', 'name' => 'Ikeja Branch',
            'type' => 'Branch', 'parent_id' => $head->id,
        ]);

        $this->seed(ControlStructureSeeder::class);

        $this->hoc = $this->unit('HOC');
        $this->brc = $this->unit('BRC');

        $this->hoc->forceFill(['head_user_id' => $this->cfh->id])->save();
        $this->brc->forceFill(['head_user_id' => $this->cfh->id])->save();

        app(FrequencyResolver::class)->flush();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function unit(string $code): ControlUnit
    {
        return ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
    }

    /**
     * A miniature of the client's workbook: the merged-cell hierarchy,
     * the dirty text, the mixed-rhythm function and the blank cells.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sampleRows(): array
    {
        $row = fn (int $n, string $sheet, string $unit, string $fn, string $chk, string $freq) => [
            'sheet' => $sheet, 'row_no' => $n, 'source_ref' => ($sheet === 'Branch Control' ? 'BR' : 'HO')."!D{$n}",
            'unit' => $unit, 'function' => $fn, 'checklist' => $chk, 'frequency' => $freq,
        ];

        return [
            // Trailing double space in the unit name, numbering baked into
            // the checklist text, and a "Quaterly" misspelling.
            $row(3, 'Head Office Control', 'Trade  Control', 'REVIEW OF FORM M', '(1) Obtain the Form M register', 'Daily'),
            $row(4, 'Head Office Control', 'Trade  Control', 'REVIEW OF FORM M', '(2) Confirm each Form M is approved', 'Daily'),
            // Same function, a different rhythm — Gap 2.
            $row(5, 'Head Office Control', 'Trade  Control', 'REVIEW OF FORM M', '(3) Reconcile the monthly position', 'Monthly'),
            // Blank frequency: inherits the function's.
            $row(6, 'Head Office Control', 'Trade  Control', 'REVIEW OF FORM M', '(4) Escalate exceptions observed', ''),
            $row(8, 'Head Office Control', 'Trade  Control', 'SECURITY SWEEP', 'Sweep the trade floor', 'Quaterly'),
            // Casing differs from the row above — one desk, not two.
            $row(10, 'Head Office Control', 'TRADE CONTROL', 'BID REVIEW', 'Review the bid file', 'As per sales by CBN'),
            $row(12, 'Branch Control', 'BRANCH INTERNAL CONTROL', 'REVIEW OF GL MOVEMENTS', '(1) Download the GL movement report', 'Daily'),
            $row(13, 'Branch Control', 'BRANCH INTERNAL CONTROL', 'REVIEW OF GL MOVEMENTS', '(2) Identify unusual balances', 'Daily'),
            $row(15, 'Branch Control', 'BRANCH INTERNAL CONTROL', 'BRANCH AMBIENCE', 'Observe the branch ambience', 'Observation'),
        ];
    }

    private function importSample(): ControlFunctionImport
    {
        return app(ControlFunctionImportService::class)->import(
            $this->sampleRows(),
            ['source_name' => 'sample.xlsx', 'source_hash' => str_repeat('a', 64)],
            $this->tenant->id,
            $this->admin,
        );
    }

    private function control(string $title): Control
    {
        return Control::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->firstOrFail();
    }

    // ── Gap 1: the frequency vocabulary (§C.1) ───────────────────────

    public function test_the_workbooks_own_spellings_all_resolve(): void
    {
        $resolver = app(FrequencyResolver::class);

        $expected = [
            'Daily' => 'daily',
            'Weekly' => 'weekly',
            'Monthly' => 'monthly',
            // The source misspelling.
            'Quaterly' => 'quarterly',
            // Four spellings of twice a year (§G.1 — confirm before go-live).
            'bi-annually' => 'semiannual',
            'twice annually' => 'semiannual',
            'Half yearly' => 'semiannual',
            'Yearly' => 'annual',
            'On request' => 'on_request',
            'Observation' => 'observation',
            'As per sales by CBN' => 'cbn_fx_sale',
            'Anytime there a new circular' => 'cbn_circular',
        ];

        foreach ($expected as $raw => $code) {
            $this->assertSame($code, $resolver->resolve($raw, $this->tenant->id)?->code, "\"{$raw}\" must resolve to {$code}.");
        }

        // Case and whitespace are noise, not meaning.
        $this->assertSame('daily', $resolver->resolve("  DAILY\u{a0} ", $this->tenant->id)?->code);
        // Blank means inherit, not Monthly.
        $this->assertNull($resolver->resolve('', $this->tenant->id));
    }

    public function test_an_unknown_frequency_fails_loudly_instead_of_defaulting_to_monthly(): void
    {
        $resolver = app(FrequencyResolver::class);

        $this->assertNull($resolver->resolve('every other Thursday', $this->tenant->id));

        $this->expectException(ValidationException::class);
        $resolver->resolveOrFail('every other Thursday', $this->tenant->id);
    }

    public function test_period_labels_are_unchanged_for_every_legacy_frequency(): void
    {
        $service = app(TestingService::class);
        $asOf = CarbonImmutable::parse('2026-05-14');

        $cases = [
            'Daily' => ['2026-05-14', '2026-05-14', '2026-05-14'],
            'Weekly' => ['W20-2026', '2026-05-11', '2026-05-17'],
            'Monthly' => ['May-2026', '2026-05-01', '2026-05-31'],
            'Quarterly' => ['Q2-2026', '2026-04-01', '2026-06-30'],
            'Semi-annual' => ['H1-2026', '2026-01-01', '2026-06-30'],
            'Annual' => ['2026', '2026-01-01', '2026-12-31'],
        ];

        foreach ($cases as $frequency => [$label, $start, $end]) {
            [$actualLabel, $actualStart, $actualEnd] = $service->periodFor($frequency, $asOf);

            $this->assertSame($label, $actualLabel, "{$frequency} period label must not change.");
            $this->assertSame($start, $actualStart->toDateString());
            $this->assertSame($end, $actualEnd->toDateString());
        }
    }

    public function test_grace_days_drive_the_due_date_per_rhythm(): void
    {
        $resolver = app(FrequencyResolver::class);
        $asOf = CarbonImmutable::parse('2026-05-14');

        $daily = $resolver->period($resolver->byCode('daily'), $asOf);
        $monthly = $resolver->period($resolver->byCode('monthly'), $asOf);
        $observation = $resolver->period($resolver->byCode('observation'), $asOf);

        $this->assertSame('2026-05-15', $daily['due']->toDateString(), 'Daily grace is one day.');
        // Matches the due_date = period_end + 5 the platform has always used.
        $this->assertSame('2026-06-05', $monthly['due']->toDateString());
        $this->assertNull($observation['due'], 'A continuous task has no deadline and never goes overdue.');
    }

    // ── Gap 4: the importer (§D) ─────────────────────────────────────

    public function test_the_importer_forward_fills_normalises_and_upserts(): void
    {
        $import = $this->importSample();

        $this->assertSame('Committed', $import->status);
        $this->assertSame(9, $import->rows_total);
        $this->assertSame(0, $import->rows_unresolved);

        // Four functions: three head office, one branch — plus ambience.
        $functions = Control::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('is_control_function', true)->get();

        $this->assertCount(5, $functions);

        // Case-insensitive unit keys: "Trade  Control" and "TRADE CONTROL"
        // are one desk, not two.
        $desks = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('control_unit_id', $this->hoc->id)
            ->where('is_import_created', true)
            ->pluck('name');

        $this->assertCount(1, $desks);
        $this->assertSame('Trade Control', $desks->first(), 'Trailing double spaces must be normalised away.');

        // Numbering prefixes are stripped from the checklist text.
        $formM = $this->control('REVIEW OF FORM M');
        $questions = $formM->activeTestScript()->checkItems()->orderBy('sequence')->pluck('question')->all();

        $this->assertSame([
            'Obtain the Form M register',
            'Confirm each Form M is approved',
            'Reconcile the monthly position',
            'Escalate exceptions observed',
        ], $questions);

        // The client's own wording survives for the audit trail.
        $this->assertSame('Daily', $formM->frequency_raw);
        $this->assertNotNull($formM->source_ref);
    }

    public function test_a_repeat_import_of_the_same_file_changes_nothing(): void
    {
        $this->importSample();

        $before = [
            'controls' => Control::withoutGlobalScopes()->where('is_control_function', true)->count(),
            'items' => CheckItem::query()->count(),
            'scripts' => TestScript::withoutGlobalScopes()->count(),
        ];

        $second = $this->importSample();

        $this->assertSame(0, $second->controls_added);
        $this->assertSame(0, $second->scripts_versioned);
        $this->assertSame($before['controls'], Control::withoutGlobalScopes()->where('is_control_function', true)->count());
        $this->assertSame($before['items'], CheckItem::query()->count());
        $this->assertSame($before['scripts'], TestScript::withoutGlobalScopes()->count());
    }

    public function test_an_edited_workbook_drafts_v2_and_leaves_v1_executing(): void
    {
        $this->importSample();

        $control = $this->control('REVIEW OF FORM M');
        $v1 = $control->activeTestScript();
        $this->assertSame(1, $v1->version_no);
        $this->assertSame('Active', $v1->status);

        $rows = $this->sampleRows();
        $rows[1]['checklist'] = '(2) Confirm each Form M is approved AND stamped';

        app(ControlFunctionImportService::class)->import(
            $rows,
            ['source_name' => 'sample-v2.xlsx', 'source_hash' => str_repeat('b', 64)],
            $this->tenant->id,
            $this->admin,
        );

        $control->refresh();
        $scripts = $control->testScripts()->orderBy('version_no')->get();

        $this->assertCount(2, $scripts);
        $this->assertSame('Active', $scripts[0]->status, 'v1 must keep executing while v2 is a draft.');
        $this->assertSame('Draft', $scripts[1]->status);
        $this->assertSame(2, $scripts[1]->version_no);

        // v1's items are untouched, so historical check_results still
        // point at a real check item.
        $this->assertSame(
            'Confirm each Form M is approved',
            $scripts[0]->checkItems()->where('sequence', 2)->value('question'),
        );
    }

    public function test_an_unresolved_frequency_blocks_the_commit(): void
    {
        $rows = $this->sampleRows();
        $rows[0]['frequency'] = 'every other Thursday';

        $service = app(ControlFunctionImportService::class);

        $dryRun = $service->dryRun(
            $rows,
            ['source_name' => 'broken.xlsx'],
            $this->tenant->id,
            $this->admin,
        );

        $this->assertSame(1, $dryRun->rows_unresolved);
        $this->assertFalse($dryRun->isCommittable());

        $this->expectException(ValidationException::class);
        $service->commit($dryRun, $this->admin);
    }

    public function test_a_dry_run_writes_nothing_to_the_control_library(): void
    {
        $before = Control::withoutGlobalScopes()->count();

        $dryRun = app(ControlFunctionImportService::class)->dryRun(
            $this->sampleRows(),
            ['source_name' => 'sample.xlsx'],
            $this->tenant->id,
            $this->admin,
        );

        $this->assertSame('Dry Run', $dryRun->status);
        $this->assertSame($before, Control::withoutGlobalScopes()->count());
        $this->assertNotEmpty($dryRun->diff_report);
        $this->assertSame(9, $dryRun->rows()->count());
    }

    // ── Gap 2: line-level frequency (§C.2) ───────────────────────────

    public function test_a_mixed_rhythm_function_produces_one_instance_per_rhythm(): void
    {
        $this->importSample();

        $formM = $this->control('REVIEW OF FORM M');

        // Three daily lines dominate, so the function is Daily and the
        // monthly line carries the override.
        $this->assertSame('daily', $formM->resolvedFrequency()->code);

        $overrides = $formM->activeTestScript()->checkItems()->whereNotNull('frequency_id')->get();
        $this->assertCount(1, $overrides);
        $this->assertSame('monthly', $overrides->first()->controlFrequency->code);

        // The blank line inherits rather than defaulting to Monthly.
        $blank = $formM->activeTestScript()->checkItems()->where('sequence', 4)->first();
        $this->assertNull($blank->frequency_id);
        $this->assertNull($blank->frequency_raw);

        $tasks = app(ControlTaskService::class);
        $tasks->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        $instances = TestInstance::withoutGlobalScopes()
            ->where('control_id', $formM->id)->with('frequency')->get();

        $this->assertCount(2, $instances, 'One control, two rhythms, two tasks.');
        $this->assertEqualsCanonicalizing(
            ['daily', 'monthly'],
            $instances->pluck('frequency.code')->all(),
        );

        // And each task asks only its own lines.
        $daily = $instances->firstWhere('frequency.code', 'daily');
        $monthly = $instances->firstWhere('frequency.code', 'monthly');

        $this->assertCount(3, $tasks->checkItemsFor($daily));
        $this->assertCount(1, $tasks->checkItemsFor($monthly));
    }

    // ── Gap 3: entity-scoped instances (§C.3) ────────────────────────

    public function test_two_branches_hold_their_own_instance_of_one_function_on_one_day(): void
    {
        $this->importSample();

        $gl = $this->control('REVIEW OF GL MOVEMENTS');

        // One control, attached to both branches — not copied per branch.
        $this->assertSame(2, $gl->controlEntities()->count());

        app(ControlTaskService::class)->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        $instances = TestInstance::withoutGlobalScopes()
            ->where('control_id', $gl->id)
            ->where('period_label', '2026-05-14')
            ->get();

        $this->assertCount(2, $instances, 'Each branch owes its own occurrence of the same daily function.');
        $this->assertCount(2, $instances->pluck('control_entity_id')->unique());
        $this->assertEqualsCanonicalizing(
            $instances->pluck('control_entity_id')->map(fn ($id) => 'e'.$id)->all(),
            $instances->pluck('scope_key')->all(),
            'scope_key is derived from the entity and never written by hand.',
        );
    }

    public function test_a_second_generation_run_creates_no_duplicates(): void
    {
        $this->importSample();

        $tasks = app(ControlTaskService::class);
        $asOf = CarbonImmutable::parse('2026-05-14');

        $first = $tasks->generateForTenant($this->tenant->id, $asOf);
        $before = TestInstance::withoutGlobalScopes()->count();

        $second = $tasks->generateForTenant($this->tenant->id, $asOf);

        $this->assertGreaterThan(0, $first['created']);
        $this->assertSame(0, $second['created'], 'A second run for the same period creates nothing.');
        $this->assertSame($before, TestInstance::withoutGlobalScopes()->count());
    }

    public function test_the_unique_index_refuses_a_duplicate_scoped_instance(): void
    {
        $this->importSample();

        $gl = $this->control('REVIEW OF GL MOVEMENTS');
        $entity = $gl->controlEntities()->first();
        $daily = ControlFrequency::query()->where('code', 'daily')->first();

        $attributes = [
            'tenant_id' => $this->tenant->id,
            'control_id' => $gl->id,
            'control_entity_id' => $entity->id,
            'frequency_id' => $daily->id,
            'reference' => TestInstance::nextReference('TSK'),
            'period_label' => '2026-05-14',
            'period_start' => '2026-05-14',
            'period_end' => '2026-05-14',
            'due_date' => '2026-05-15',
            'status' => 'Scheduled',
        ];

        TestInstance::withoutGlobalScopes()->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        TestInstance::withoutGlobalScopes()->create([
            ...$attributes,
            'reference' => TestInstance::nextReference('TSK'),
        ]);
    }

    public function test_a_pre_existing_global_instance_still_generates_and_resolves(): void
    {
        // A plain library control, untouched by CR-03.
        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-LEGACY',
            'title' => 'Legacy monthly control',
            'type' => 'Detective', 'nature' => 'Manual', 'frequency' => 'Monthly',
            'status' => 'Active', 'owner_id' => $this->officer->id,
        ]);

        TestScript::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'control_id' => $control->id,
            'version_no' => 1, 'title' => 'Legacy script', 'status' => 'Active',
        ]);

        $created = app(TestingService::class)->generateScheduledInstances(CarbonImmutable::parse('2026-05-14'));
        $this->assertGreaterThan(0, $created);

        $instance = TestInstance::withoutGlobalScopes()->where('control_id', $control->id)->firstOrFail();

        $this->assertSame('global', $instance->scope_key);
        $this->assertNull($instance->control_entity_id);
        $this->assertNull($instance->frequency_id);
        $this->assertSame('May-2026', $instance->period_label);

        // And it is still idempotent.
        $this->assertSame(0, app(TestingService::class)->generateScheduledInstances(CarbonImmutable::parse('2026-05-14')));
    }

    public function test_the_legacy_job_does_not_double_generate_control_functions(): void
    {
        $this->importSample();

        $asOf = CarbonImmutable::parse('2026-05-14');
        app(ControlTaskService::class)->generateForTenant($this->tenant->id, $asOf);
        $after = TestInstance::withoutGlobalScopes()->count();

        app(TestingService::class)->generateScheduledInstances($asOf);

        $this->assertSame(
            $after,
            TestInstance::withoutGlobalScopes()->count(),
            'The legacy nightly job must leave control functions to ControlTaskService.',
        );
    }

    // ── Event-driven and continuous (§C.5) ───────────────────────────

    public function test_an_event_driven_function_is_never_auto_generated_but_can_be_triggered(): void
    {
        $this->importSample();

        $bid = $this->control('BID REVIEW');
        $this->assertSame('cbn_fx_sale', $bid->resolvedFrequency()->code);

        app(ControlTaskService::class)->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        $this->assertSame(0, TestInstance::withoutGlobalScopes()->where('control_id', $bid->id)->count());

        $instance = app(ControlTaskService::class)->raiseEventInstance(
            $bid,
            $bid->controlEntities()->first(),
            ['reason' => 'CBN sold FX this morning'],
            $this->officer,
            CarbonImmutable::parse('2026-05-14 09:30:00'),
        );

        $this->assertTrue($instance->is_ad_hoc);
        $this->assertSame('cbn_fx_sale', $instance->trigger_event);
        $this->assertSame('CBN sold FX this morning', $instance->trigger_context['reason']);
    }

    public function test_a_trigger_fans_out_across_every_function_listening_for_it(): void
    {
        $this->importSample();

        $raised = app(ControlTaskService::class)->fireTrigger(
            $this->tenant->id,
            'cbn_fx_sale',
            ['circular' => 'TED/FEM/FPC/GEN/01/2026'],
            CarbonImmutable::parse('2026-05-14 09:30:00'),
        );

        $this->assertCount(1, $raised);
        $this->assertSame('cbn_fx_sale', $raised->first()->trigger_event);
    }

    public function test_an_observation_task_has_no_deadline_and_never_goes_overdue(): void
    {
        $this->importSample();

        $ambience = $this->control('BRANCH AMBIENCE');
        $this->assertSame('observation', $ambience->resolvedFrequency()->code);

        // The nightly scheduled run leaves it alone.
        app(ControlTaskService::class)->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));
        $this->assertSame(0, TestInstance::withoutGlobalScopes()->where('control_id', $ambience->id)->count());

        $rolled = app(ControlTaskService::class)->rollContinuous(CarbonImmutable::parse('2026-05-01'));
        $this->assertSame(2, $rolled['opened'], 'One rolling observation per branch.');

        $instances = TestInstance::withoutGlobalScopes()->where('control_id', $ambience->id)->get();

        foreach ($instances as $instance) {
            $this->assertNull($instance->due_date);
            $this->assertFalse($instance->is_overdue, 'An observation must never sit in the overdue queue.');
            $this->assertSame('OBS-May-2026', $instance->period_label);
        }

        $this->assertSame(
            0,
            TestInstance::withoutGlobalScopes()->where('control_id', $ambience->id)->overdue()->count(),
            'A null due date must be excluded from the overdue scope, not treated as long past.',
        );
    }

    public function test_rolling_into_a_new_month_closes_the_superseded_observation(): void
    {
        $this->importSample();

        $service = app(ControlTaskService::class);
        $service->rollContinuous(CarbonImmutable::parse('2026-05-01'));
        $rolled = $service->rollContinuous(CarbonImmutable::parse('2026-06-01'));

        $this->assertSame(2, $rolled['opened']);
        $this->assertSame(2, $rolled['closed'], 'Last month\'s observation closes rather than going overdue.');

        $ambience = $this->control('BRANCH AMBIENCE');
        $may = TestInstance::withoutGlobalScopes()
            ->where('control_id', $ambience->id)->where('period_label', 'OBS-May-2026')->get();

        $this->assertTrue($may->every(fn ($i) => $i->status === 'Closed'));
    }

    // ── Assignment (§C.4) ────────────────────────────────────────────

    public function test_assignment_prefers_the_desks_officer_then_falls_back_up_the_chain(): void
    {
        $this->importSample();

        $service = app(ControlTaskService::class);
        $formM = $this->control('REVIEW OF FORM M');
        $desk = $formM->homeEntity;

        // Nothing on the desk: falls through to the unit head.
        [$tester, $reviewer] = $service->resolveAssignment($formM, $desk);
        $this->assertSame($this->cfh->id, $tester?->id);
        // The head cannot review what the head performed (§4 SoD).
        $this->assertNull($reviewer, 'A reviewer who is also the tester is worse than none — review() refuses it anyway.');

        $desk->forceFill(['default_officer_id' => $this->officer->id])->save();
        $formM->refresh()->load('homeEntity');

        [$tester, $reviewer] = $service->resolveAssignment($formM, $formM->homeEntity);
        $this->assertSame($this->officer->id, $tester?->id, 'The desk officer wins over the unit head.');
        $this->assertSame($this->cfh->id, $reviewer?->id, 'The unit head reviews.');
    }

    public function test_generated_tasks_land_in_the_desk_officers_queue(): void
    {
        $this->importSample();

        $branch = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('entity_kind', 'branch')
            ->where('name', 'Marina Branch')
            ->firstOrFail();

        $branch->forceFill(['default_officer_id' => $this->officer->id])->save();

        app(ControlTaskService::class)->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        $mine = TestInstance::withoutGlobalScopes()
            ->where('assigned_tester_id', $this->officer->id)
            ->where('control_entity_id', $branch->id)
            ->count();

        $this->assertGreaterThan(0, $mine);
    }

    // ── Branch provisioning (§D.2) ───────────────────────────────────

    public function test_a_new_branch_inherits_the_whole_function_set_on_the_day_it_opens(): void
    {
        $this->importSample();

        $gl = $this->control('REVIEW OF GL MOVEMENTS');
        $this->assertSame(2, $gl->controlEntities()->count());

        OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'BR-003', 'name' => 'Apapa Branch', 'type' => 'Branch',
        ]);

        $newBranch = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('entity_kind', 'branch')
            ->where('name', 'Apapa Branch')
            ->firstOrFail();

        $this->assertTrue(
            $gl->controlEntities()->where('control_entities.id', $newBranch->id)->exists(),
            'A branch that opens today owes the branch checklist today.',
        );

        // And it is attachment, not duplication: still one control.
        $this->assertSame(
            1,
            Control::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant->id)
                ->whereRaw('lower(title) = ?', ['review of gl movements'])
                ->count(),
        );
    }

    // ── Reporting (§E.4) ─────────────────────────────────────────────

    public function test_frequency_compliance_reports_expected_against_actual(): void
    {
        $this->importSample();

        $tasks = app(ControlTaskService::class);

        foreach (['2026-05-11', '2026-05-12', '2026-05-13'] as $day) {
            $tasks->generateForTenant($this->tenant->id, CarbonImmutable::parse($day));
        }

        $rows = $tasks->frequencyCompliance(
            $this->tenant->id,
            CarbonImmutable::parse('2026-05-11'),
            CarbonImmutable::parse('2026-05-15'),
        );

        $gl = collect($rows)->firstWhere('title', 'REVIEW OF GL MOVEMENTS');

        // Five days across two branches, three of them generated.
        $this->assertSame(10, $gl['expected']);
        $this->assertSame(6, $gl['actual']);
        $this->assertSame(4, $gl['gap'], 'The gap is what an examiner asks about.');
    }

    public function test_compliance_counts_a_period_that_straddles_the_window(): void
    {
        $this->importSample();

        $tasks = app(ControlTaskService::class);
        $tasks->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        // A one-day window inside May. The monthly REVIEW OF FORM M task
        // has period_start 2026-05-01 — before the window opens — and
        // must still be counted: an examiner asking about a boundary
        // month is asking about exactly this row.
        $rows = $tasks->frequencyCompliance(
            $this->tenant->id,
            CarbonImmutable::parse('2026-05-14'),
            CarbonImmutable::parse('2026-05-14'),
        );

        $formM = collect($rows)->firstWhere('title', 'REVIEW OF FORM M');

        $this->assertSame(2, $formM['actual'], 'Both rhythms\' occurrences overlap the window.');
        $this->assertSame(0, $formM['gap']);

        $units = $tasks->completionByUnit(
            $this->tenant->id,
            CarbonImmutable::parse('2026-05-14'),
            CarbonImmutable::parse('2026-05-14'),
        );

        $this->assertGreaterThan(0, collect($units)->sum('total'));
    }

    // ── Routes and permissions (§E.3) ────────────────────────────────

    public function test_the_catalogue_renders_for_a_control_officer(): void
    {
        $this->importSample();

        $this->actingAs($this->officer)
            ->get(route('control-functions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ControlFunctions/Index')
                ->where('summary.functions', 5));
    }

    public function test_importing_is_the_administrators_act_alone(): void
    {
        $this->actingAs($this->officer)->get(route('control-functions.import.index'))->assertForbidden();
        $this->actingAs($this->cfh)->get(route('control-functions.import.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('control-functions.import.index'))->assertOk();
    }

    public function test_a_branch_officer_sees_only_their_own_branchs_tasks(): void
    {
        $this->importSample();

        $branches = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('entity_kind', 'branch')
            ->orderBy('name')
            ->get();

        $marina = $branches->firstWhere('name', 'Marina Branch');
        $ikeja = $branches->firstWhere('name', 'Ikeja Branch');

        $mine = $this->makeUser('marina@checklist.test', 'Control Officer');
        $theirs = $this->makeUser('ikeja@checklist.test', 'Control Officer');

        $marina->forceFill(['default_officer_id' => $mine->id])->save();
        $ikeja->forceFill(['default_officer_id' => $theirs->id])->save();

        app(ControlTaskService::class)->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        $marinaTask = TestInstance::withoutGlobalScopes()->where('control_entity_id', $marina->id)->firstOrFail();

        $this->assertTrue($mine->can('view', $marinaTask));
        $this->assertFalse(
            $theirs->can('view', $marinaTask),
            'At the client\'s branch count, one officer must not see several thousand other branches\' checklists.',
        );
        // Oversight still reaches everything.
        $this->assertTrue($this->cfh->can('view', $marinaTask));
    }

    public function test_my_work_shows_only_the_lines_of_the_tasks_own_rhythm(): void
    {
        $this->importSample();

        $formM = $this->control('REVIEW OF FORM M');
        $formM->homeEntity->forceFill(['default_officer_id' => $this->officer->id])->save();

        app(ControlTaskService::class)->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'));

        $feed = app(MyWorkService::class)->feed($this->officer->fresh());

        $tasks = collect($feed['test_instances'])->filter(
            fn ($task) => $task['control']['title'] === 'REVIEW OF FORM M',
        )->values();

        $this->assertCount(2, $tasks, 'One task per rhythm.');

        $daily = $tasks->firstWhere('frequency.code', 'daily');
        $monthly = $tasks->firstWhere('frequency.code', 'monthly');

        $this->assertCount(3, $daily['check_items']);
        $this->assertCount(1, $monthly['check_items']);
        $this->assertSame('Trade Control', $daily['control_entity']['name']);
    }

    public function test_the_register_round_trips_back_into_the_clients_own_layout(): void
    {
        $this->importSample();

        $book = app(ControlFunctionExportService::class)->build($this->tenant->id);

        $this->assertSame(
            ['Head Office Control', 'Branch Control'],
            $book->getSheetNames(),
            'The export must carry the sheets the client recognises.',
        );

        $sheet = $book->getSheetByName('Head Office Control');

        // Row 1 is the spacer the source opens with; row 2 the header.
        $this->assertSame('S/N', $sheet->getCell('A2')->getValue());
        $this->assertSame('Frequency of Activity', $sheet->getCell('E2')->getValue());

        // Units and Function written once, blank on continuation rows.
        $this->assertNotEmpty($sheet->getCell('B3')->getValue());
        $this->assertNotEmpty($sheet->getCell('C3')->getValue());
        $this->assertNull($sheet->getCell('C4')->getValue(), 'A continuation row leaves Function blank.');
        $this->assertNotEmpty($sheet->getCell('D4')->getValue());

        // And the frequency goes back out in the bank's own wording.
        $sweep = collect(range(3, 40))
            ->map(fn ($row) => [$sheet->getCell("D{$row}")->getValue(), $sheet->getCell("E{$row}")->getValue()])
            ->first(fn ($pair) => $pair[0] === 'Sweep the trade floor');

        $this->assertSame('Quaterly', $sweep[1], 'Their spelling, not ours — otherwise they reconcile by hand.');
    }

    public function test_a_dry_run_of_the_generator_writes_nothing(): void
    {
        $this->importSample();

        $totals = app(ControlTaskService::class)
            ->generateForTenant($this->tenant->id, CarbonImmutable::parse('2026-05-14'), dryRun: true);

        $this->assertGreaterThan(0, $totals['created']);
        $this->assertSame(0, TestInstance::withoutGlobalScopes()->count());
    }

    public function test_the_module_is_gated_by_its_feature_flag(): void
    {
        FeatureFlag::query()->where('key', 'control-functions')->update(['is_enabled' => false]);
        app(FeatureService::class)->flush();

        $this->actingAs($this->officer)->get(route('control-functions.index'))->assertNotFound();
    }
}
