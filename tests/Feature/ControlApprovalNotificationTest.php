<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ControlApprovalNotification;
use App\Services\ControlService;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Maker-checker on controls reaches the people who have to act, and a
 * returned control tells its maker why.
 */
class ControlApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private User $cfh;

    private User $owner;

    private Control $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->officer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->officer->assignRole('Control Officer');

        $this->cfh = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->cfh->assignRole('Control Function Head');

        $this->owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->owner->assignRole('Control Owner');

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'control_ref' => 'CTL-500',
            'title' => 'Same-user approval block',
            'type' => 'Preventive',
            'nature' => 'Automated',
            'frequency' => 'Daily',
            'status' => 'Draft',
            'current_version' => 1,
            'created_by' => $this->officer->id,
            'owner_id' => $this->owner->id,
        ]);

        Notification::fake();
    }

    public function test_submitting_asks_the_control_function_head_to_approve(): void
    {
        $this->actingAs($this->officer)->post(route('controls.submit', $this->control))->assertSessionHasNoErrors();

        $this->assertSame('Pending Approval', $this->control->fresh()->status);
        Notification::assertSentTo($this->cfh, ControlApprovalNotification::class,
            fn ($n) => $n->kind === ControlApprovalNotification::SUBMITTED);
        Notification::assertNotSentTo($this->officer, ControlApprovalNotification::class);
    }

    public function test_the_maker_is_never_asked_to_approve_their_own_control(): void
    {
        $this->control->update(['created_by' => $this->cfh->id]);

        app(ControlService::class)->submitForApproval($this->control->fresh(), $this->cfh);

        Notification::assertNotSentTo($this->cfh, ControlApprovalNotification::class);
    }

    public function test_returning_tells_the_maker_and_owner_why_and_shows_it_on_the_control(): void
    {
        $this->control->update(['status' => 'Pending Approval']);

        $this->actingAs($this->cfh)
            ->post(route('controls.reject', $this->control), ['reason' => 'Link the transfer risk first'])
            ->assertSessionHasNoErrors();

        $control = $this->control->fresh();
        $this->assertSame('Draft', $control->status);
        $this->assertSame('Link the transfer risk first', $control->return_reason);
        $this->assertSame($this->cfh->id, $control->returned_by);

        foreach ([$this->officer, $this->owner] as $user) {
            Notification::assertSentTo($user, ControlApprovalNotification::class,
                fn ($n) => $n->kind === ControlApprovalNotification::RETURNED && $n->note === 'Link the transfer risk first');
        }

        $this->actingAs($this->officer)
            ->get(route('controls.show', $this->control))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('control.return_reason', 'Link the transfer risk first')
                ->where('control.returner.name', $this->cfh->name));
    }

    public function test_resubmitting_clears_the_return_reason(): void
    {
        $this->control->update(['return_reason' => 'Fix it', 'returned_by' => $this->cfh->id, 'returned_at' => now()]);

        $this->actingAs($this->officer)->post(route('controls.submit', $this->control));

        $this->assertNull($this->control->fresh()->return_reason);
        $this->assertNull($this->control->fresh()->returned_by);
    }

    public function test_approving_tells_the_maker_and_owner(): void
    {
        $this->control->update(['status' => 'Pending Approval']);

        $this->actingAs($this->cfh)->post(route('controls.approve', $this->control))->assertSessionHasNoErrors();

        $this->assertSame('Active', $this->control->fresh()->status);
        Notification::assertSentTo([$this->officer, $this->owner], ControlApprovalNotification::class,
            fn ($n) => $n->kind === ControlApprovalNotification::APPROVED);
        Notification::assertNotSentTo($this->cfh, ControlApprovalNotification::class);
    }

    public function test_amending_an_active_control_puts_it_back_in_front_of_the_checker(): void
    {
        $this->control->update(['status' => 'Active']);

        app(ControlService::class)->amend($this->control->fresh(), ['title' => 'Amended title'], $this->officer, 'Scope change');

        $this->assertSame('Pending Approval', $this->control->fresh()->status);
        Notification::assertSentTo($this->cfh, ControlApprovalNotification::class,
            fn ($n) => $n->kind === ControlApprovalNotification::SUBMITTED);
    }

    public function test_the_library_counts_only_what_the_checker_may_approve(): void
    {
        $this->control->update(['status' => 'Pending Approval']);
        Control::withoutGlobalScopes()->create([
            ...$this->control->only(['tenant_id', 'type', 'nature', 'frequency', 'owner_id']),
            'control_ref' => 'CTL-501',
            'title' => 'Made by the checker',
            'status' => 'Pending Approval',
            'current_version' => 1,
            'created_by' => $this->cfh->id,
        ]);

        $this->actingAs($this->cfh)
            ->get(route('controls.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('awaitingMyApproval', 1));

        $this->actingAs($this->officer)
            ->get(route('controls.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('awaitingMyApproval', 0));
    }

    public function test_a_control_notification_opens_the_control(): void
    {
        $id = $this->officer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ControlApprovalNotification::class,
            'data' => ['control_id' => $this->control->id, 'summary' => 'Test'],
        ])->id;

        $this->actingAs($this->officer)
            ->get(route('notifications.open', $id))
            ->assertRedirect(route('controls.show', $this->control->id));
    }
}
