<?php

namespace Tests\Feature;

use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExceptionDecisionNotification;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The approval hand-offs on an exception reach the person who has to act,
 * and the control function can only decide an extension someone asked for.
 */
class ExceptionApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $cfh;

    private User $owner;

    private ControlException $exception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->cfh->assignRole('Control Function Head');

        $this->owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->owner->assignRole('Control Owner');

        $this->exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'reference' => 'EXC-2026-001',
            'source_type' => 'Manual',
            'title' => 'Transfer approved by its initiator',
            'severity' => 'High',
            'status' => 'Assigned',
            'owner_id' => $this->owner->id,
            'responsible_party_id' => $this->owner->id,
            'raised_by' => $this->cfh->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(10)->toDateString(),
        ]);

        Notification::fake();
    }

    public function test_an_extension_request_reaches_the_control_function_head(): void
    {
        $this->actingAs($this->owner)
            ->post(route('exceptions.request-extension', $this->exception), [
                'new_date' => now()->addDays(30)->toDateString(),
                'reason' => 'Vendor patch lands next month',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->cfh, ExceptionDecisionNotification::class,
            fn ($n) => $n->kind === ExceptionDecisionNotification::EXTENSION_REQUESTED);
        Notification::assertNotSentTo($this->owner, ExceptionDecisionNotification::class);
    }

    public function test_an_extension_cannot_be_decided_until_one_is_requested(): void
    {
        $this->assertFalse($this->cfh->can('decideExtension', $this->exception));

        $this->actingAs($this->cfh)
            ->post(route('exceptions.decide-extension', $this->exception), [
                'approved' => true,
                'new_date' => now()->addDays(30)->toDateString(),
                'reason' => 'No request was made',
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->exception->fresh()->extension_count);
    }

    public function test_deciding_a_request_tells_the_owner_and_clears_it(): void
    {
        $this->actingAs($this->owner)->post(route('exceptions.request-extension', $this->exception), [
            'new_date' => now()->addDays(30)->toDateString(),
            'reason' => 'Vendor patch lands next month',
        ]);

        $this->assertTrue($this->cfh->can('decideExtension', $this->exception->fresh()));

        $this->actingAs($this->cfh)
            ->post(route('exceptions.decide-extension', $this->exception), [
                'approved' => true,
                'new_date' => now()->addDays(30)->toDateString(),
                'reason' => 'Agreed',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->owner, ExceptionDecisionNotification::class,
            fn ($n) => $n->kind === ExceptionDecisionNotification::EXTENSION_DECIDED && $n->approved === true);
        $this->assertSame(1, $this->exception->fresh()->extension_count);
        $this->assertFalse($this->cfh->can('decideExtension', $this->exception->fresh()));
    }

    public function test_marking_remediated_asks_the_control_function_head_to_verify(): void
    {
        $this->actingAs($this->owner)
            ->post(route('exceptions.remediated', $this->exception), ['note' => 'Approver role restricted'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->cfh, ExceptionDecisionNotification::class,
            fn ($n) => $n->kind === ExceptionDecisionNotification::REMEDIATED);
    }

    public function test_opening_a_notification_marks_it_read_and_lands_on_the_exception(): void
    {
        $id = $this->storeNotification($this->owner);

        $this->actingAs($this->owner)
            ->get(route('notifications.open', $id))
            ->assertRedirect(route('exceptions.show', $this->exception->id));

        $this->assertNotNull($this->owner->notifications()->find($id)->read_at);
    }

    public function test_a_user_cannot_open_someone_elses_notification(): void
    {
        $id = $this->storeNotification($this->owner);

        $this->actingAs($this->cfh)->get(route('notifications.open', $id))->assertNotFound();

        $this->assertNull($this->owner->notifications()->find($id)->read_at);
    }

    private function storeNotification(User $user): string
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ExceptionDecisionNotification::class,
            'data' => ['exception_id' => $this->exception->id, 'summary' => 'Test'],
        ])->id;
    }
}
