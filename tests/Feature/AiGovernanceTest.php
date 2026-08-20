<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\AiPrompt;
use App\Models\CheckItem;
use App\Models\CheckResult;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\AiDraft;
use App\Services\Ai\AiService;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use Database\Seeders\AiPromptSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * R4 — AI never decides — and the governance surface around it.
 *
 * The central assertions here are the ones that try to break the rule:
 * applying a draft nobody accepted, applying one twice, applying an advisory
 * capability's output to a record, and accepting under a role a tenant has
 * restricted. Every one of them must fail.
 */
class AiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $head;

    private Control $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(AiPromptSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');

        $this->actingAs($this->officer);

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-001',
            'title' => 'Dual authorisation of GL postings',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);

        foreach (['control.draft', 'exception.triage', 'evidence.review'] as $capability) {
            AiConfiguration::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'capability_key' => $capability,
                'is_enabled' => true,
            ]);
        }
    }

    private User $admin;

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function completedInteraction(string $capability, array $output, ?User $user = null, $subject = null): AiInteraction
    {
        return AiInteraction::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => ($user ?? $this->officer)->id,
            'capability_key' => $capability,
            'prompt_version' => 1,
            'model' => 'granite4:micro',
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'status' => 'Completed',
            'parsed_output' => $output,
            'confidence' => 0.8,
            'human_decision' => 'Pending',
        ]);
    }

    private function controlDraftOutput(): array
    {
        return [
            'title' => 'Dual authorisation of outward transfers',
            'objective' => 'Prevent an outward transfer being released by its originator.',
            'description' => 'The operations officer releases each transfer above the threshold.',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'test_script' => [
                'name' => 'Release testing',
                'check_items' => [['assertion' => 'Releaser differs from originator.']],
            ],
            'confidence' => 0.8,
        ];
    }

    // ── R4: nothing becomes real without a recorded human decision ──────

    /** THE structural test. An AcceptedDraft cannot exist over a Pending row. */
    public function test_an_accepted_draft_cannot_be_constructed_from_an_undecided_interaction(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        $this->expectException(AiDecisionRequiredException::class);
        $this->expectExceptionMessage('has not been accepted by a person');

        new AcceptedDraft($interaction, $this->officer, $this->controlDraftOutput());
    }

    /** And it cannot be constructed by someone other than the recorded approver. */
    public function test_an_accepted_draft_cannot_be_constructed_by_a_different_approver(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());
        $interaction->update([
            'human_decision' => 'Accepted',
            'decided_by' => $this->officer->id,
            'decided_at' => now(),
        ]);

        $this->expectException(AiDecisionRequiredException::class);
        $this->expectExceptionMessage('does not match the person applying it');

        new AcceptedDraft($interaction, $this->head, $this->controlDraftOutput());
    }

    public function test_a_draft_cannot_be_accepted_twice(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        app(AiService::class)->accept($interaction, $this->officer);

        $this->expectException(AiDecisionRequiredException::class);
        $this->expectExceptionMessage('already been');

        app(AiService::class)->accept($interaction->refresh(), $this->officer);
    }

    public function test_a_failed_draft_cannot_be_accepted(): void
    {
        $interaction = $this->completedInteraction('control.draft', []);
        $interaction->update(['status' => 'Rejected by Schema']);

        $this->expectException(AiDecisionRequiredException::class);

        AiDraft::fromInteraction($interaction->refresh())->accept($this->officer);
    }

    /**
     * Evidence review is advisory by contract — "never sets a result". It has
     * no apply(), so attempting to make its output real fails.
     */
    public function test_an_advisory_capability_cannot_change_a_record(): void
    {
        $script = TestScript::create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $this->control->id,
            'version_no' => 1,
            'title' => 'Script',
            'status' => 'Active',
        ]);

        $item = CheckItem::create([
            'test_script_id' => $script->id,
            'sequence' => 1,
            'question' => 'Is dual authorisation evidenced?',
            'is_mandatory' => true,
            'default_severity_on_fail' => 'High',
        ]);

        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $this->control->id,
            'test_script_id' => $script->id,
            'reference' => 'TST-2026-001',
            'period_label' => '2026-08',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'In Progress',
        ]);

        $result = CheckResult::create([
            'test_instance_id' => $instance->id,
            'check_item_id' => $item->id,
            // Deliberately unset: the assertion below is that an advisory
            // capability leaves it exactly as it found it.
            'result' => null,
        ]);

        $interaction = $this->completedInteraction('evidence.review', [
            'supports_assertion' => true,
            'confidence' => 0.9,
            'rationale' => 'The release log covers the period.',
        ], subject: $result);

        // Accepting is allowed — a person may record that they read it.
        $outcome = app(AiService::class)->accept($interaction, $this->officer);

        $this->assertNull($outcome['applied'], 'An advisory capability must create nothing.');
        $this->assertNull($result->refresh()->result, 'The check result must be untouched.');

        // And going at the capability directly is refused outright.
        $this->expectException(AiDecisionRequiredException::class);

        app(AiService::class)->capability('evidence.review')->apply(
            new AcceptedDraft($interaction->refresh(), $this->officer, []),
            $this->officer,
        );
    }

    // ── What accepting actually produces ────────────────────────────────

    /**
     * An accepted control draft lands as a Draft, unapproved, with the
     * accepting person as its creator — so maker-checker still applies and
     * they cannot then approve their own control (R2).
     */
    public function test_an_accepted_control_draft_is_created_unapproved_and_still_needs_a_checker(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        $outcome = app(AiService::class)->accept($interaction, $this->officer);
        $control = $outcome['applied'];

        $this->assertInstanceOf(Control::class, $control);
        $this->assertSame('Draft', $control->status);
        $this->assertNull($control->approved_by);
        $this->assertSame($this->officer->id, $control->created_by);

        // Its script is a draft too — an AI-written test script is exactly
        // what a second person should read before anyone tests against it.
        $this->assertSame('Draft', $control->testScripts()->first()->status);

        // Maker-checker survives: the accepter is the creator, so the
        // existing rule refuses their approval.
        $this->assertFalse($this->officer->can('approve', $control));

        // And the trail runs both ways.
        $this->assertSame($control->getMorphClass(), $interaction->refresh()->applied_type);
        $this->assertSame($control->id, $interaction->applied_id);
    }

    public function test_accepting_with_edits_is_recorded_as_edited_with_a_diff(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        $outcome = app(AiService::class)->accept($interaction, $this->officer, [
            'frequency' => 'Event-driven',
        ]);

        $interaction = $outcome['interaction'];

        $this->assertSame('Edited', $interaction->human_decision);
        $this->assertSame(['from' => 'Daily', 'to' => 'Event-driven'], $interaction->edit_diff['frequency']);
        $this->assertSame('Event-driven', $outcome['applied']->frequency);
    }

    /**
     * Triage writes the root cause and the plan. It must not move the
     * exception along its lifecycle — that chain ends with a Control
     * Function Head verifying a fix they did not perform (R2).
     */
    public function test_accepted_triage_updates_the_narrative_but_never_the_status(): void
    {
        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-2026-001',
            'source_type' => 'Manual',
            'control_id' => $this->control->id,
            'title' => 'Reconciliation not performed',
            'severity' => 'High',
            'date_raised' => now()->toDateString(),
            'status' => 'Open',
        ]);

        $interaction = $this->completedInteraction('exception.triage', [
            'category' => 'Process design',
            'probable_root_cause' => 'No named deputy for the reconciliation.',
            'root_cause_confidence' => 0.7,
            'recurrence_risk' => 'High',
            'proposed_remediation' => [['action' => 'Name a deputy.', 'owner_role' => 'Line Manager']],
            'confidence' => 0.8,
        ], subject: $exception);

        app(AiService::class)->accept($interaction, $this->officer);

        $exception->refresh();

        $this->assertStringContainsString('deputy', $exception->root_cause);
        $this->assertStringContainsString('Name a deputy', $exception->remediation_plan);
        $this->assertSame('Open', $exception->status, 'Triage must never advance the lifecycle.');
    }

    public function test_triage_cannot_be_applied_to_a_closed_exception(): void
    {
        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-2026-002',
            'source_type' => 'Manual',
            'control_id' => $this->control->id,
            'title' => 'Historic exception',
            'severity' => 'Low',
            'date_raised' => now()->subYear()->toDateString(),
            'status' => 'Verified-Closed',
        ]);

        $interaction = $this->completedInteraction('exception.triage', [
            'category' => 'Process design',
            'probable_root_cause' => 'Something.',
            'confidence' => 0.8,
        ], subject: $exception);

        $this->expectException(AiDecisionRequiredException::class);
        $this->expectExceptionMessage('closed');

        app(AiService::class)->accept($interaction, $this->officer);
    }

    // ── Tenant-configured approval narrowing ────────────────────────────

    /**
     * requires_approval_role_id tightens the gate. It never opens one — the
     * capability's own domain permission still applies underneath (R2).
     */
    public function test_a_configured_approval_role_is_required_in_addition_to_the_permission(): void
    {
        AiConfiguration::withoutGlobalScopes()
            ->where('capability_key', 'control.draft')
            ->update(['requires_approval_role_id' => Role::where('name', 'Control Function Head')->value('id')]);

        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        $this->expectException(AiDecisionRequiredException::class);
        $this->expectExceptionMessage('Control Function Head');

        app(AiService::class)->accept($interaction, $this->officer);
    }

    // ── Rejection ───────────────────────────────────────────────────────

    public function test_rejecting_records_a_reason_as_feedback(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        app(AiService::class)->reject($interaction, $this->officer, 'Duplicates CTL-001.', 'irrelevant');

        $interaction->refresh();

        $this->assertSame('Rejected', $interaction->human_decision);
        $this->assertSame('Duplicates CTL-001.', $interaction->rejection_reason);
        $this->assertSame('irrelevant', $interaction->feedback()->first()->issue_category);
    }

    public function test_the_decide_route_requires_a_reason_when_rejecting(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput());

        $this->actingAs($this->officer)
            ->post(route('ai.interactions.decide', $interaction), ['decision' => 'reject'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('Pending', $interaction->refresh()->human_decision);
    }

    public function test_a_user_cannot_decide_on_someone_elses_draft(): void
    {
        $interaction = $this->completedInteraction('control.draft', $this->controlDraftOutput(), $this->head);

        $this->actingAs($this->officer)
            ->post(route('ai.interactions.decide', $interaction), ['decision' => 'accept'])
            ->assertForbidden();
    }

    // ── The governance surface ──────────────────────────────────────────

    public function test_the_governance_page_renders_for_an_administrator(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Ai/Index')
                ->has('capabilities', 9)
                ->has('budget')
                ->has('quality'));
    }

    /** The page must never hand the key to the browser (R9). */
    public function test_the_governance_page_never_exposes_the_api_key(): void
    {
        config(['services.ollama.api_key' => 'ollama-proxy-secret-value-000000']);

        $response = $this->actingAs($this->admin)->get(route('admin.ai.index'));

        $response->assertOk();
        $this->assertStringNotContainsString('ollama-proxy-secret-value-000000', $response->getContent());
        $this->assertStringNotContainsString('api_key', $response->getContent());
    }

    public function test_a_control_officer_cannot_reach_the_governance_page(): void
    {
        $this->actingAs($this->officer)->get(route('admin.ai.index'))->assertForbidden();
    }

    public function test_publishing_a_prompt_creates_a_new_version_and_never_edits_the_old_one(): void
    {
        $shipped = AiPrompt::where('key', 'control.draft')->whereNull('tenant_id')->first();
        $originalSystem = $shipped->system_prompt;

        $this->actingAs($this->head)
            ->post(route('admin.ai.prompts.store'), [
                'key' => 'control.draft',
                'system_prompt' => 'A revised system prompt.',
                'user_template' => 'A revised template with {{source_description}}.',
                'changelog' => 'Tightened the objective instruction.',
            ])
            ->assertRedirect();

        $this->assertSame($originalSystem, $shipped->refresh()->system_prompt, 'The shipped version must be untouched.');

        $new = AiPrompt::where('key', 'control.draft')
            ->where('tenant_id', $this->tenant->id)->first();

        $this->assertNotNull($new);
        $this->assertSame(2, $new->version);
        // Saved inactive: nothing changes for users until it is made live.
        $this->assertFalse($new->is_active);
    }

    public function test_the_activity_log_export_is_itself_audited(): void
    {
        $this->completedInteraction('control.draft', $this->controlDraftOutput());

        $this->actingAs($this->admin)->get(route('admin.ai.log.export'))->assertOk();

        $this->assertDatabaseHas('audit_trails', ['action' => 'ai_log_exported']);
    }
}
