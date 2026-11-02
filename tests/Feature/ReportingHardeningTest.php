<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ReportDefinitionNotification;
use App\Services\ReportDesignerService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\ReportDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * QA reporting and exports: approval gates, confidentiality on run and
 * download, tenant-scoped recipients, hand-off notices and export audit.
 */
class ReportingHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $officer2;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->officer2 = $this->makeUser('officer2@test.local', 'Control Officer');

        $this->actingAs($this->cfh);
        $this->seed(ReportDefinitionSeeder::class);
    }

    private function makeUser(string $email, string $role, ?Tenant $tenant = null): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => ($tenant ?? $this->tenant)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function designer(): ReportDesignerService
    {
        return app(ReportDesignerService::class);
    }

    private function definition(User $owner, string $confidentiality = 'Internal', string $code = 'QA-1'): ReportDefinition
    {
        return $this->designer()->createDefinition([
            'code' => $code,
            'name' => 'QA report '.$code,
            'report_type' => 'management',
            'confidentiality' => $confidentiality,
            'output_formats' => ['pdf'],
            'sections' => [['type' => 'cover', 'title' => 'Cover']],
        ], $owner);
    }

    private function system(string $code): ReportDefinition
    {
        return ReportDefinition::withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }

    // ── 1. Only approved definitions run or are scheduled ────────────────

    public function test_an_unapproved_definition_cannot_be_run(): void
    {
        $definition = $this->definition($this->officer);

        $this->assertFalse($this->officer->can('run', $definition));
        $this->actingAs($this->officer)
            ->post(route('reports.definitions.run', $definition->id), ['output_format' => 'pdf'])
            ->assertForbidden();
        $this->assertSame(0, ReportRun::count());

        $this->designer()->approveDefinition($definition, $this->cfh);

        $this->actingAs($this->officer)
            ->post(route('reports.definitions.run', $definition->id), ['output_format' => 'pdf'])
            ->assertRedirect();
        $this->assertSame(1, ReportRun::count());
    }

    public function test_editing_an_approved_definition_blocks_running_until_it_is_approved_again(): void
    {
        $definition = $this->definition($this->officer);
        $this->designer()->approveDefinition($definition, $this->cfh);
        $this->designer()->updateDefinition($definition, [
            'sections' => [['type' => 'cover', 'title' => 'Cover'], ['type' => 'narrative', 'title' => 'New']],
        ], $this->officer);

        $this->assertFalse($this->officer->can('run', $definition->fresh()));
    }

    public function test_a_system_definition_stays_runnable_without_an_approval(): void
    {
        $definition = $this->system('TESTING-SUMMARY');

        $this->assertNull($definition->approved_at);
        $this->assertTrue($this->officer->can('run', $definition));
    }

    public function test_an_unapproved_definition_cannot_be_scheduled_or_run_now(): void
    {
        $definition = $this->definition($this->officer);

        $payload = [
            'report_definition_id' => $definition->id,
            'name' => 'Weekly',
            'cron' => '0 7 * * 1',
            'timezone' => 'Africa/Lagos',
            'output_format' => 'pdf',
            'delivery_method' => 'in_app',
            'recipients' => ['user_ids' => [$this->cfh->id]],
        ];

        $this->actingAs($this->cfh)
            ->post(route('reports.schedules.store'), $payload)
            ->assertSessionHasErrors('report_definition_id');
        $this->assertSame(0, ReportSchedule::count());

        $schedule = ReportSchedule::create([
            'tenant_id' => $this->tenant->id, 'report_definition_id' => $definition->id, 'name' => 'Existing',
            'cron' => '0 7 * * 1', 'timezone' => 'Africa/Lagos', 'output_format' => 'pdf',
            'recipients' => ['user_ids' => [$this->cfh->id]], 'delivery_method' => 'in_app',
            'is_active' => true, 'created_by' => $this->cfh->id, 'next_run_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->cfh)
            ->post(route('reports.schedules.run-now', $schedule->id))
            ->assertStatus(422);

        $this->artisan('reports:run-scheduled')->assertSuccessful();
        $this->assertSame(0, $schedule->runs()->count());

        $this->designer()->approveDefinition($definition, $this->cfh);
        $this->actingAs($this->cfh)
            ->post(route('reports.schedules.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame(2, ReportSchedule::count());
    }

    // ── 2. The approver is neither the author nor the last editor ────────

    public function test_the_last_editor_cannot_approve_their_own_edit(): void
    {
        $definition = $this->definition($this->officer);
        $this->assertSame($this->officer->id, $definition->last_edited_by);

        $this->designer()->updateDefinition($definition, ['name' => 'Renamed'], $this->cfh);

        $definition->refresh();
        $this->assertSame($this->cfh->id, $definition->last_edited_by);
        $this->assertFalse($this->cfh->can('approve', $definition));

        $this->actingAs($this->cfh)
            ->post(route('reports.definitions.approve', $definition->id))
            ->assertForbidden();

        try {
            $this->designer()->approveDefinition($definition, $this->cfh);
            $this->fail('The last editor approved their own edit.');
        } catch (ValidationException) {
            $this->assertNull($definition->fresh()->approved_at);
        }

        $admin = $this->makeUser('admin@test.local', 'System Administrator');
        $this->designer()->approveDefinition($definition, $admin);
        $this->assertNotNull($definition->fresh()->approved_at);
    }

    // ── 3. Confidentiality on run and download ───────────────────────────

    public function test_a_control_officer_cannot_run_or_download_a_board_report(): void
    {
        $board = $this->system('BOARD-PACK');
        $this->assertSame('Board', $board->confidentiality);

        $this->assertFalse($this->officer->can('run', $board));
        $this->actingAs($this->officer)
            ->post(route('reports.definitions.run', $board->id), ['output_format' => 'pdf'])
            ->assertForbidden();

        $run = $this->designer()->run($board, $this->cfh, 'pdf');
        $this->assertSame('Completed', $run->status);

        $this->actingAs($this->officer2)
            ->get(route('reports.runs.download', $run->id))
            ->assertForbidden();

        $this->actingAs($this->cfh)
            ->get(route('reports.runs.download', $run->id))
            ->assertOk();

        $this->actingAs($this->cfh)
            ->get(route('reports.runs.download', ['run' => $run->id, 'token' => 'wrong']))
            ->assertForbidden();
    }

    public function test_the_run_list_only_shows_runs_the_user_may_open(): void
    {
        $boardRun = $this->designer()->run($this->system('BOARD-PACK'), $this->cfh, 'pdf');
        $plainRun = $this->designer()->run($this->system('TESTING-SUMMARY'), $this->cfh, 'pdf');

        $refs = fn (User $user) => collect(
            $this->actingAs($user)->get(route('reports.runs'))->assertOk()->viewData('page')['props']['runs']['data'],
        )->pluck('run_ref')->all();

        $this->assertContains($boardRun->run_ref, $refs($this->cfh));
        $this->assertNotContains($boardRun->run_ref, $refs($this->officer));
        $this->assertContains($plainRun->run_ref, $refs($this->officer));
    }

    // ── 4. Tenant scoping of role recipients ─────────────────────────────

    public function test_a_role_recipient_list_does_not_reach_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $stranger = $this->makeUser('stranger@other.local', 'Control Function Head', $other);

        $definition = $this->system('TESTING-SUMMARY');
        $schedule = ReportSchedule::create([
            'tenant_id' => $this->tenant->id, 'report_definition_id' => $definition->id, 'name' => 'Role based',
            'cron' => '0 7 1 * *', 'timezone' => 'Africa/Lagos', 'output_format' => 'pdf',
            'recipients' => ['role_names' => ['Control Function Head']], 'delivery_method' => 'in_app',
            'is_active' => true, 'created_by' => $this->cfh->id,
        ]);

        $run = $this->designer()->run($definition, $this->cfh, 'pdf', [], $schedule);
        $delivered = collect($this->designer()->distribute($run, $schedule))->pluck('user_id')->all();

        $this->assertContains($this->cfh->id, $delivered);
        $this->assertNotContains($stranger->id, $delivered);
    }

    // ── 5. Definition notifications ──────────────────────────────────────

    public function test_creating_and_editing_a_definition_asks_the_approvers_but_not_its_maker(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin@test.local', 'System Administrator');

        $definition = $this->definition($this->officer);

        Notification::assertSentTo([$this->cfh, $admin], ReportDefinitionNotification::class,
            fn ($n) => $n->kind === ReportDefinitionNotification::APPROVAL_REQUESTED);
        Notification::assertNotSentTo([$this->officer, $this->officer2], ReportDefinitionNotification::class);

        Notification::fake();
        $this->designer()->updateDefinition($definition, ['name' => 'Renamed'], $this->cfh);

        Notification::assertSentTo($admin, ReportDefinitionNotification::class);
        Notification::assertNotSentTo($this->cfh, ReportDefinitionNotification::class);
        Notification::assertNotSentTo($this->officer, ReportDefinitionNotification::class);
    }

    public function test_approving_a_definition_tells_its_owner(): void
    {
        $definition = $this->definition($this->officer);
        Notification::fake();

        $this->designer()->approveDefinition($definition, $this->cfh);

        Notification::assertSentTo($this->officer, ReportDefinitionNotification::class,
            fn ($n) => $n->kind === ReportDefinitionNotification::APPROVED);
        Notification::assertNotSentTo($this->cfh, ReportDefinitionNotification::class);
    }

    // ── 6. Exports are audited ───────────────────────────────────────────

    public function test_direct_report_exports_are_audited_with_their_filters(): void
    {
        $this->actingAs($this->cfh)
            ->get(route('reports.exceptions.xlsx', ['status' => 'open', 'severity' => 'High']))
            ->assertOk();

        $row = AuditTrail::where('action', 'report.exported')->latest('id')->firstOrFail();
        $this->assertSame($this->cfh->id, $row->user_id);
        $this->assertSame('exceptions.xlsx', $row->after['report']);
        $this->assertSame(['status' => 'open', 'severity' => 'High'], $row->after['filters']);

        foreach (['reports.controls.xlsx', 'reports.test-instances.xlsx', 'reports.testing-summary'] as $name) {
            $this->actingAs($this->cfh)->get(route($name))->assertOk();
        }
        $this->assertSame(4, AuditTrail::where('action', 'report.exported')->count());
    }

    public function test_the_user_list_export_is_audited(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');

        $this->actingAs($admin)->get(route('admin.users.export'))->assertOk();

        $this->assertSame(1, AuditTrail::where('action', 'users_exported')->where('user_id', $admin->id)->count());
    }
}
