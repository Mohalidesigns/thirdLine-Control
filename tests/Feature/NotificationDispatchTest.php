<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OwnerDigestNotification;
use App\Services\NotificationDispatcher;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationEventSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'timezone' => 'Africa/Lagos']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Control Owner');
    }

    private function digest(): OwnerDigestNotification
    {
        return new OwnerDigestNotification(collect());
    }

    public function test_default_channels_come_from_the_seeded_event(): void
    {
        Notification::fake();

        app(NotificationDispatcher::class)->send($this->user, 'owner.digest', $this->digest());

        Notification::assertSentTo($this->user, OwnerDigestNotification::class,
            fn ($notification) => $notification->resolvedChannels === ['database', 'mail']);
    }

    public function test_a_disabled_channel_is_suppressed(): void
    {
        Notification::fake();

        NotificationPreference::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'event_key' => 'owner.digest',
            'channel' => 'email',
            'is_enabled' => false,
        ]);

        app(NotificationDispatcher::class)->send($this->user, 'owner.digest', $this->digest());

        Notification::assertSentTo($this->user, OwnerDigestNotification::class,
            fn ($notification) => $notification->resolvedChannels === ['database']);
    }

    public function test_disabling_every_channel_sends_nothing(): void
    {
        Notification::fake();

        foreach (['in_app', 'email'] as $channel) {
            NotificationPreference::withoutGlobalScope('tenant')->create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $this->user->id,
                'event_key' => 'owner.digest',
                'channel' => $channel,
                'is_enabled' => false,
            ]);
        }

        app(NotificationDispatcher::class)->send($this->user, 'owner.digest', $this->digest());

        Notification::assertNotSentTo($this->user, OwnerDigestNotification::class);
    }

    public function test_quiet_hours_defer_delivery_until_the_window_ends(): void
    {
        Notification::fake();
        // 23:00 Lagos time — inside a 22:00→06:30 quiet window.
        Carbon::setTestNow(Carbon::parse('2026-08-07 22:00:00', 'UTC')); // 23:00 in Africa/Lagos (UTC+1)

        NotificationPreference::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'event_key' => 'owner.digest',
            'channel' => 'email',
            'is_enabled' => true,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '06:30',
        ]);

        app(NotificationDispatcher::class)->send($this->user, 'owner.digest', $this->digest());

        // Two sends: the in-app alert immediately, the email deferred to 06:30 +1 day Lagos time.
        Notification::assertSentTo($this->user, OwnerDigestNotification::class,
            fn ($notification) => $notification->resolvedChannels === ['database'] && $notification->delay === null);

        Notification::assertSentTo($this->user, OwnerDigestNotification::class, function ($notification) {
            return $notification->resolvedChannels === ['mail']
                && $notification->delay !== null
                && Carbon::instance($notification->delay)->setTimezone('Africa/Lagos')->format('H:i') === '06:30';
        });

        Carbon::setTestNow();
    }

    public function test_daily_digest_frequency_defers_to_the_next_digest_window(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00', 'UTC'));

        NotificationPreference::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'event_key' => 'owner.digest',
            'channel' => 'email',
            'is_enabled' => true,
            'digest_frequency' => 'daily',
        ]);

        app(NotificationDispatcher::class)->send($this->user, 'owner.digest', $this->digest());

        Notification::assertSentTo($this->user, OwnerDigestNotification::class, function ($notification) {
            return $notification->resolvedChannels === ['mail']
                && $notification->delay !== null
                && Carbon::instance($notification->delay)->setTimezone('Africa/Lagos')->format('H:i') === '07:00';
        });

        Carbon::setTestNow();
    }

    public function test_non_configurable_events_ignore_user_preferences(): void
    {
        Notification::fake();

        // Attempt to disable a security event.
        NotificationPreference::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'event_key' => 'security.mfa-policy-changed',
            'channel' => 'email',
            'is_enabled' => false,
        ]);

        app(NotificationDispatcher::class)->send($this->user, 'security.mfa-policy-changed', $this->digest());

        Notification::assertSentTo($this->user, OwnerDigestNotification::class,
            fn ($notification) => $notification->resolvedChannels === ['database', 'mail']);
    }

    public function test_user_can_save_their_own_preferences(): void
    {
        $this->actingAs($this->user)
            ->put(route('settings.notifications.update'), [
                'preferences' => [[
                    'event_key' => 'owner.digest',
                    'channel' => 'email',
                    'is_enabled' => false,
                    'digest_frequency' => 'weekly',
                ]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->user->id,
            'event_key' => 'owner.digest',
            'channel' => 'email',
            'is_enabled' => false,
            'digest_frequency' => 'weekly',
        ]);
    }
}
