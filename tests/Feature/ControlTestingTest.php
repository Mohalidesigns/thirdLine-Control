<?php

namespace Tests\Feature;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\RatingMatrixEntry;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use App\Services\TestingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RatingMatrixSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ControlTestingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $tester;

    private User $reviewer;

    private Control $control;

    private TestScript $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RatingMatrixSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->tester = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tester->assignRole('Control Officer');

        $this->reviewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->reviewer->assignRole('Control Function Head');

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-100',
            'title' => 'Reconciliation control',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);

        $this->script = TestScript::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $this->control->id,
            'version_no' => 1,
            'title' => 'Recon test',
            'status' => 'Active',
        ]);

        CheckItem::create([
            'test_script_id' => $this->script->id,
            'sequence' => 1,
            'question' => 'Was the reconciliation prepared?',
            'is_mandatory' => true,
            'default_severity_on_fail' => 'High',
        ]);

        CheckItem::create([
            'test_script_id' => $this->script->id,
            'sequence' => 2,
            'question' => 'Was it independently reviewed?',
            'is_mandatory' => true,
            'default_severity_on_fail' => 'Critical',
        ]);
    }

    private function makeInstance(): TestInstance
    {
        return TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $this->control->id,
            'test_script_id' => $this->script->id,
            'reference' => 'TST-'.fake()->unique()->numberBetween(100, 999),
            'period_label' => 'Jul-2026',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_date' => now()->addDays(5),
            'assigned_tester_id' => $this->tester->id,
            'status' => 'In Progress',
        ]);
    }

    // ── FR-3.4: idempotent scheduler ─────────────────────────────────

    public function test_scheduler_is_idempotent(): void
    {
        $service = app(TestingService::class);
        $asOf = CarbonImmutable::parse('2026-08-05');

        $first = $service->generateScheduledInstances($asOf);
        $second = $service->generateScheduledInstances($asOf);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Re-running the scheduler must not duplicate instances.');
        $this->assertSame(1, TestInstance::withoutGlobalScopes()->where('control_id', $this->control->id)->count());
    }

    // ── FR-3.2: mandatory comment on Fail/NA ─────────────────────────

    public function test_fail_without_comment_is_rejected(): void
    {
        $instance = $this->makeInstance();

        $this->expectException(ValidationException::class);
        app(TestingService::class)->recordResult($instance, $this->script->checkItems[0]->id, 'Fail', null, $this->tester);
    }

    // ── FR-3.6: auto-exception from failed checks ────────────────────

    public function test_submission_raises_an_exception_for_every_failed_check(): void
    {
        $service = app(TestingService::class);
        $instance = $this->makeInstance();
        [$item1, $item2] = $this->script->checkItems;

        $service->recordResult($instance, $item1->id, 'Fail', 'Reconciliation missing for 3 days', $this->tester);
        $service->recordResult($instance, $item2->id, 'Pass', null, $this->tester);
        $service->submit($instance, [], $this->tester);

        $exceptions = ControlException::withoutGlobalScopes()->where('source_id', $instance->id)->get();

        $this->assertCount(1, $exceptions);
        $this->assertSame('High', $exceptions->first()->severity);
        $this->assertSame('Test', $exceptions->first()->source_type);

        // Resubmission must not duplicate the exception.
        $instance->update(['status' => 'In Progress']);
        $service->submit($instance, [], $this->tester);
        $this->assertSame(1, ControlException::withoutGlobalScopes()->where('source_id', $instance->id)->count());
    }

    // ── FR-3.9: locked after sign-off ────────────────────────────────

    public function test_reviewed_test_is_locked_until_formally_reopened(): void
    {
        $service = app(TestingService::class);
        $instance = $this->makeInstance();
        [$item1, $item2] = $this->script->checkItems;

        $service->recordResult($instance, $item1->id, 'Pass', null, $this->tester);
        $service->recordResult($instance, $item2->id, 'Pass', null, $this->tester);
        $service->submit($instance, [], $this->tester);
        $service->review($instance, $this->reviewer, true);

        $this->assertSame('Reviewed', $instance->fresh()->status);

        try {
            $service->recordResult($instance->fresh(), $item1->id, 'Fail', 'change after lock', $this->tester);
            $this->fail('Recording against a locked test should throw.');
        } catch (ValidationException) {
            // expected
        }

        $service->reopen($instance->fresh(), $this->reviewer, 'Late evidence received');
        $this->assertSame('Reopened', $instance->fresh()->status);
    }

    // ── FR-7.4: matrix-driven overall rating ─────────────────────────

    public function test_overall_rating_derives_from_the_seeded_matrix(): void
    {
        $this->assertSame('Ineffective', RatingMatrixEntry::resolve('Ineffective', 'Effective', $this->tenant->id));
        $this->assertSame('Partially Effective', RatingMatrixEntry::resolve('Effective', 'Partially Effective', $this->tenant->id));
        $this->assertSame('Effective', RatingMatrixEntry::resolve('Effective', 'Effective', $this->tenant->id));
    }

    public function test_rating_requires_rationale_when_not_effective(): void
    {
        $instance = $this->makeInstance();

        $this->expectException(ValidationException::class);
        app(TestingService::class)->rate($instance, [
            'design_effectiveness' => 'Ineffective',
            'design_rationale' => '',
            'operating_effectiveness' => 'Effective',
            'operating_rationale' => null,
        ], $this->tester);
    }
}
