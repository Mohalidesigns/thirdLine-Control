<?php

namespace Tests\Feature;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use App\Notifications\TestInstanceNotification;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RatingMatrixSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A control test reaches the person who has to act at each hand-off, and
 * the control owner the scheduler assigns a test to can actually run it.
 */
class ControlTestingWorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private User $cfh;

    private User $secondCfh;

    private User $owner;

    private Control $control;

    private TestInstance $instance;

    private CheckItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RatingMatrixSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->officer = $this->makeUser($tenant, 'Control Officer');
        $this->cfh = $this->makeUser($tenant, 'Control Function Head');
        $this->secondCfh = $this->makeUser($tenant, 'Control Function Head');
        $this->owner = $this->makeUser($tenant, 'Control Owner');

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'control_ref' => 'CTL-600',
            'title' => 'Quarterly access recertification',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Quarterly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
        ]);

        $script = TestScript::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'control_id' => $this->control->id,
            'version_no' => 1,
            'title' => 'Recertification test',
            'status' => 'Active',
        ]);

        $this->item = CheckItem::create([
            'test_script_id' => $script->id,
            'sequence' => 1,
            'question' => 'Did the recertification cover every in-scope application?',
            'is_mandatory' => true,
            'default_severity_on_fail' => 'Medium',
        ]);

        $this->instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'control_id' => $this->control->id,
            'test_script_id' => $script->id,
            'reference' => 'TST-2026-600',
            'period_label' => 'Q4-2026',
            'period_start' => '2026-10-01',
            'period_end' => '2026-12-31',
            'due_date' => '2027-01-05',
            'assigned_tester_id' => $this->officer->id,
            'reviewer_id' => $this->cfh->id,
            'status' => 'Scheduled',
        ]);

        Notification::fake();
    }

    private function makeUser(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function performAndSubmit(User $tester): void
    {
        $this->actingAs($tester)->post(route('test-instances.start', $this->instance))->assertSessionHasNoErrors();
        $this->actingAs($tester)->post(route('test-instances.results', $this->instance), [
            'check_item_id' => $this->item->id, 'result' => 'Pass',
        ])->assertSessionHasNoErrors();
        $this->actingAs($tester)->post(route('test-instances.submit', $this->instance))->assertSessionHasNoErrors();
    }

    private function rate(User $rater): void
    {
        $this->actingAs($rater)->post(route('test-instances.rate', $this->instance), [
            'design_effectiveness' => 'Effective',
            'operating_effectiveness' => 'Partially Effective',
            'operating_rationale' => 'One application was missed in the recertification.',
        ])->assertSessionHasNoErrors();
    }

    public function test_the_control_owner_the_scheduler_assigns_can_run_their_test(): void
    {
        $this->instance->update(['assigned_tester_id' => $this->owner->id]);

        $this->performAndSubmit($this->owner);

        $this->assertSame('Submitted', $this->instance->fresh()->status);
    }

    public function test_a_control_owner_cannot_run_a_test_assigned_to_someone_else(): void
    {
        $this->actingAs($this->owner)->post(route('test-instances.start', $this->instance))->assertForbidden();
    }

    public function test_submitting_asks_the_named_reviewer_to_review(): void
    {
        $this->performAndSubmit($this->officer);

        Notification::assertSentTo($this->cfh, TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::SUBMITTED);
        Notification::assertNotSentTo([$this->officer, $this->secondCfh], TestInstanceNotification::class);
    }

    public function test_without_a_named_reviewer_every_control_function_head_is_asked(): void
    {
        $this->instance->update(['reviewer_id' => null]);

        $this->performAndSubmit($this->officer);

        Notification::assertSentTo([$this->cfh, $this->secondCfh], TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::SUBMITTED);
    }

    public function test_the_tester_is_never_asked_to_review_their_own_test(): void
    {
        $this->instance->update(['assigned_tester_id' => $this->cfh->id, 'reviewer_id' => null]);

        $this->performAndSubmit($this->cfh);

        Notification::assertSentTo($this->secondCfh, TestInstanceNotification::class);
        Notification::assertNotSentTo($this->cfh, TestInstanceNotification::class);
    }

    public function test_returning_tells_the_tester_why_and_shows_it_on_the_test(): void
    {
        $this->performAndSubmit($this->officer);

        $this->actingAs($this->cfh)->post(route('test-instances.review', $this->instance), [
            'approved' => false, 'notes' => 'Attach the recertification sign-off sheet.',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->officer, TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::RETURNED
                && str_contains($n->toMail($this->officer)->render(), 'Attach the recertification sign-off sheet.'));

        $this->actingAs($this->officer)->get(route('test-instances.show', $this->instance))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('instance.status', 'In Progress')
                ->where('instance.review_notes', 'Attach the recertification sign-off sheet.')
                ->where('instance.reviewer.name', $this->cfh->name));
    }

    public function test_signing_off_tells_the_tester(): void
    {
        $this->performAndSubmit($this->officer);

        $this->actingAs($this->cfh)->post(route('test-instances.review', $this->instance), ['approved' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('Reviewed', $this->instance->fresh()->status);
        Notification::assertSentTo($this->officer, TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::SIGNED_OFF);
    }

    public function test_a_proposed_rating_asks_the_other_control_function_heads_to_approve(): void
    {
        $this->performAndSubmit($this->officer);
        $this->rate($this->cfh);

        Notification::assertSentTo($this->secondCfh, TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::RATING_PENDING);
        Notification::assertNotSentTo($this->cfh, TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::RATING_PENDING);
    }

    public function test_publishing_a_rating_tells_its_proposer_and_the_control_owner(): void
    {
        $this->performAndSubmit($this->officer);
        $this->rate($this->officer);

        $this->actingAs($this->cfh)->post(route('test-instances.approve-rating', $this->instance))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo([$this->officer, $this->owner], TestInstanceNotification::class,
            fn ($n) => $n->kind === TestInstanceNotification::RATING_PUBLISHED);
    }

    public function test_the_approver_sees_the_rationale_and_who_proposed_the_rating(): void
    {
        $this->performAndSubmit($this->officer);
        $this->rate($this->officer);

        $this->actingAs($this->cfh)->get(route('test-instances.show', $this->instance))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('instance.effectiveness_rating.operating_rationale', 'One application was missed in the recertification.')
                ->where('instance.effectiveness_rating.rater.name', $this->officer->name));
    }
}
