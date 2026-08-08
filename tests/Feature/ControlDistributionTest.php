<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlDistribution;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DistributionService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ControlDistributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $ownerA;

    private User $ownerB;

    private OrganisationUnit $entityA;

    private OrganisationUnit $entityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->ownerA = $this->makeUser('owner-a@test.local', 'Control Owner');
        $this->ownerB = $this->makeUser('owner-b@test.local', 'Control Owner');

        $this->entityA = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'LAG', 'name' => 'Lagos Subsidiary',
            'type' => 'Branch', 'head_user_id' => $this->ownerA->id,
        ]);
        $this->entityB = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'ABJ', 'name' => 'Abuja Subsidiary',
            'type' => 'Branch', 'head_user_id' => $this->ownerB->id,
        ]);

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeGroupControl(array $overrides = []): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-'.fake()->unique()->numberBetween(100, 999),
            'title' => 'Daily reconciliation of suspense accounts',
            'description' => 'All suspense accounts reconciled daily.',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
            'library_level' => 'group',
            'is_distributable' => true,
            'created_by' => $this->officer->id,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
            ...$overrides,
        ]);
    }

    private function distribute(Control $control, array $entityIds, array $options = [])
    {
        return app(DistributionService::class)->distribute($control, $entityIds, $this->officer, $options);
    }

    // ── 9.1: distribution creates entity instances ───────────────────

    public function test_distribution_creates_one_child_control_per_entity(): void
    {
        $group = $this->makeGroupControl();

        $distributions = $this->distribute($group, [$this->entityA->id, $this->entityB->id]);

        $this->assertCount(2, $distributions);
        $this->assertSame(2, $group->entityInstances()->count());

        $childA = $group->entityInstances()->where('unit_id', $this->entityA->id)->first();
        $this->assertSame('entity', $childA->library_level);
        $this->assertSame($group->title, $childA->title);
        $this->assertSame($this->ownerA->id, $childA->owner_id);
        $this->assertSame('Not Started', $childA->implementation_status);
        $this->assertNotSame($group->control_ref, $childA->control_ref);
    }

    public function test_distribution_is_idempotent_per_entity(): void
    {
        $group = $this->makeGroupControl();

        $this->distribute($group, [$this->entityA->id]);
        $this->distribute($group, [$this->entityA->id, $this->entityA->id]);

        $this->assertSame(1, $group->entityInstances()->count());
        $this->assertSame(1, ControlDistribution::where('control_id', $group->id)->count());
    }

    public function test_only_an_active_distributable_group_control_can_be_distributed(): void
    {
        $draft = $this->makeGroupControl(['status' => 'Draft']);
        $entityLevel = $this->makeGroupControl(['library_level' => 'entity']);
        $notDistributable = $this->makeGroupControl(['is_distributable' => false]);

        foreach ([$draft, $entityLevel, $notDistributable] as $control) {
            try {
                $this->distribute($control, [$this->entityA->id]);
                $this->fail('Distribution should have been rejected for control '.$control->control_ref);
            } catch (ValidationException) {
                $this->assertSame(0, ControlDistribution::where('control_id', $control->id)->count());
            }
        }
    }

    // ── 9.1: change propagation never overwrites a local adaptation ──

    public function test_group_change_does_not_overwrite_local_adaptation(): void
    {
        $group = $this->makeGroupControl(['frequency' => 'Daily']);
        $this->distribute($group, [$this->entityA->id, $this->entityB->id]);

        // Entity A adapts locally: weekly instead of daily.
        $childA = $group->entityInstances()->where('unit_id', $this->entityA->id)->first();
        $childA->update(['frequency' => 'Weekly']);

        app(DistributionService::class)->propagate($group, ['frequency' => 'Monthly'], 'propagate', $this->officer);

        $this->assertSame('Monthly', $group->fresh()->frequency, 'The group master takes the change');
        $this->assertSame('Weekly', $childA->fresh()->frequency, 'Local adaptation must be preserved');
        $childB = $group->entityInstances()->where('unit_id', $this->entityB->id)->first();
        $this->assertSame('Monthly', $childB->fresh()->frequency, 'Inherited value must follow the group');
    }

    public function test_notify_only_mode_changes_nothing(): void
    {
        $group = $this->makeGroupControl(['frequency' => 'Daily']);
        $this->distribute($group, [$this->entityA->id]);

        app(DistributionService::class)->propagate($group, ['frequency' => 'Quarterly'], 'notify_only', $this->officer);

        $this->assertSame('Daily', $group->entityInstances()->first()->frequency);
    }

    // ── Decline requires reason + CFH approval (R2) ──────────────────

    public function test_decline_takes_effect_only_after_cfh_approval(): void
    {
        $group = $this->makeGroupControl();
        $distribution = $this->distribute($group, [$this->entityA->id])->first();

        $this->actingAs($this->ownerA);
        $this->assertTrue($this->ownerA->can('requestDecline', $distribution));

        app(DistributionService::class)->requestDecline($distribution, 'Not applicable to our licence category', $this->ownerA);
        $this->assertSame('Decline Requested', $distribution->fresh()->status);
        $this->assertSame('Active', $distribution->childControl->fresh()->status);

        app(DistributionService::class)->approveDecline($distribution->fresh(), $this->cfh);

        $distribution->refresh();
        $this->assertSame('Declined', $distribution->status);
        $this->assertSame($this->cfh->id, $distribution->decline_approved_by);
        $this->assertSame('Retired', $distribution->childControl->fresh()->status);
        $this->assertTrue($distribution->isGap(), 'A declined control still reports as a coverage gap');
    }

    public function test_the_requesting_owner_cannot_approve_their_own_decline(): void
    {
        $group = $this->makeGroupControl();
        $distribution = $this->distribute($group, [$this->entityA->id])->first();

        app(DistributionService::class)->requestDecline($distribution, 'We disagree with this control', $this->ownerA);

        $this->assertFalse($this->ownerA->can('decideDecline', $distribution->fresh()));

        $this->expectException(ValidationException::class);
        app(DistributionService::class)->approveDecline(
            $distribution->fresh()->fill(['assigned_owner_id' => $this->ownerA->id]),
            $this->ownerA,
        );
    }

    public function test_a_non_cfh_cannot_decide_a_decline(): void
    {
        $group = $this->makeGroupControl();
        $distribution = $this->distribute($group, [$this->entityA->id])->first();

        app(DistributionService::class)->requestDecline($distribution, 'Reason long enough here', $this->ownerA);

        $this->assertFalse($this->officer->can('decideDecline', $distribution->fresh()));
        $this->assertFalse($this->ownerB->can('decideDecline', $distribution->fresh()));

        $this->actingAs($this->officer)
            ->post(route('distributions.decide-decline', $distribution), ['decision' => 'approve'])
            ->assertForbidden();
    }

    // ── 9.2: task progress rolls up ──────────────────────────────────

    public function test_task_completion_drives_distribution_and_control_progress(): void
    {
        $group = $this->makeGroupControl();
        $distribution = $this->distribute($group, [$this->entityA->id], [
            'tasks' => [
                ['title' => 'Adopt procedure locally'],
                ['title' => 'Train the team'],
            ],
        ])->first();

        app(DistributionService::class)->acknowledge($distribution, $this->ownerA);

        $service = app(DistributionService::class);
        [$first, $second] = $distribution->tasks()->get();

        $service->updateTask($first, ['status' => 'Complete'], $this->ownerA);

        $distribution->refresh();
        $this->assertSame(50, $distribution->progress);
        $this->assertSame('In Progress', $distribution->status);
        $this->assertSame('In Progress', $distribution->childControl->fresh()->implementation_status);

        $service->updateTask($second, ['status' => 'Complete'], $this->ownerA);

        $distribution->refresh();
        $this->assertSame(100, $distribution->progress);
        $this->assertSame('Completed', $distribution->status);
        $this->assertSame('Implemented', $distribution->childControl->fresh()->implementation_status);
        $this->assertSame(100, $distribution->childControl->fresh()->implementation_progress);
    }

    public function test_only_the_assigned_owner_can_acknowledge(): void
    {
        $group = $this->makeGroupControl();
        $distribution = $this->distribute($group, [$this->entityA->id])->first();

        $this->assertTrue($this->ownerA->can('acknowledge', $distribution));
        $this->assertFalse($this->ownerB->can('acknowledge', $distribution));
        $this->assertFalse($this->cfh->can('acknowledge', $distribution));
    }
}
