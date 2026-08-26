<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Evidence;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsequenceService;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The control-specific extensions (§F) and the chase engine.
 *
 * These are the parts that make the module worth more inside a control
 * product than it is inside an internal audit one: a finding that names
 * the control that failed and surfaces on that control's page, an exhibit
 * that inherits the investigation's confidentiality, and a chase that
 * follows a consequence nobody carried out.
 */
class InvestigationExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    private User $officer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->outsider = $this->makeUser('outsider@test.local', 'Control Officer');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function control(): Control
    {
        return Control::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-042'],
            [
                'title' => 'Daily till reconciliation', 'type' => 'Detective',
                'nature' => 'Manual', 'frequency' => 'Daily', 'status' => 'Active',
            ],
        );
    }

    private function open(array $overrides = []): Investigation
    {
        $this->actingAs($this->officer);

        return app(InvestigationService::class)->open([
            'title' => 'Suppressed lodgements',
            'category' => 'fraud',
            'source' => 'control_exception',
            'priority' => 'High',
            ...$overrides,
        ], $this->officer);
    }

    // ── §F.2 — the control implication panel ─────────────────────────

    public function test_a_finding_surfaces_on_the_control_it_names(): void
    {
        $control = $this->control();
        $investigation = $this->open();

        app(InvestigationService::class)->addFinding($investigation, [
            'title' => 'The reconciliation did not operate for five weeks',
            'severity' => 'Critical',
            'control_id' => $control->id,
        ], $this->officer);

        $this->actingAs($this->officer)
            ->get(route('controls.show', $control->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('investigationFindings', 1)
                ->where('investigationFindings.0.severity', 'Critical'));
    }

    public function test_a_confidential_finding_does_not_surface_on_the_control_page_for_others(): void
    {
        $control = $this->control();
        $investigation = $this->open(['is_confidential' => true]);

        app(InvestigationService::class)->addFinding($investigation, [
            'title' => 'The reconciliation did not operate',
            'severity' => 'Critical',
            'control_id' => $control->id,
        ], $this->officer);

        $this->actingAs($this->outsider)
            ->get(route('controls.show', $control->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('investigationFindings', 0));
    }

    // ── Exhibits inherit the case's confidentiality ──────────────────

    public function test_an_exhibit_on_a_confidential_case_is_not_downloadable_by_an_outsider(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        $evidence = Evidence::create([
            'tenant_id' => $this->tenant->id,
            'linked_type' => Investigation::class,
            'linked_id' => $investigation->id,
            'file_name' => 'cctv-still.png',
            'storage_path' => 'evidence/2026/08/cctv-still.png',
            'checksum' => str_repeat('b', 64),
            'uploaded_by' => $this->officer->id,
            'uploaded_at' => now(),
        ]);

        Storage::disk('local')->put($evidence->storage_path, 'image-bytes');

        // A control officer with the right role but no seat on the case.
        $this->actingAs($this->outsider)
            ->get(route('evidence.download', $evidence->id))
            ->assertForbidden();

        $this->actingAs($this->officer)
            ->get(route('evidence.download', $evidence->id))
            ->assertOk();
    }

    public function test_filing_an_exhibit_records_chain_of_custody_and_the_chronology(): void
    {
        $investigation = $this->open();

        $this->actingAs($this->officer)
            ->post(route('investigations.evidence.store', $investigation->id), [
                'file' => UploadedFile::fake()->create('cbs-extract.csv', 12, 'text/csv'),
                'contains_personal_data' => true,
                'personal_data_categories' => ['Account numbers'],
                'classification' => 'Confidential',
                'collection_source' => 'CBS extract, Branch 042',
                'collected_on' => now()->toDateString(),
            ])
            ->assertRedirect();

        $evidence = Evidence::query()->where('linked_id', $investigation->id)->first();

        $this->assertNotNull($evidence);
        $this->assertSame('CBS extract, Branch 042', $evidence->collection_source);
        $this->assertSame($this->officer->id, $evidence->collected_by);
        $this->assertNotNull($evidence->checksum, 'The repository already hashes every file — no second hash column needed.');

        $this->assertTrue(
            $investigation->activities()->where('activity_type', 'evidence_collected')->exists(),
            'An exhibit belongs on the chronology, not only in a file list.',
        );
    }

    // ── The chase engine ─────────────────────────────────────────────

    public function test_an_investigation_one_day_past_its_target_chases_its_lead(): void
    {
        $this->open(['target_completion_date' => now()->subDay()->toDateString()]);

        $this->artisan('investigations:chase')->assertSuccessful();

        $this->assertSame(1, $this->notificationCount('investigation_overdue'));
    }

    public function test_the_chase_is_idempotent_within_a_day(): void
    {
        $this->open(['target_completion_date' => now()->subDay()->toDateString()]);

        $this->artisan('investigations:chase');
        $this->artisan('investigations:chase');

        $this->assertSame(1, $this->notificationCount('investigation_overdue'), 'A second run the same day must send nothing.');
    }

    public function test_the_chase_repeats_weekly_rather_than_daily(): void
    {
        // Three days past target: not day 1, not day 8.
        $this->open(['target_completion_date' => now()->subDays(3)->toDateString()]);

        $this->artisan('investigations:chase');

        $this->assertSame(0, $this->notificationCount('investigation_overdue'), 'A daily nag is how a real chase gets filtered to a folder.');
    }

    public function test_a_suspended_case_is_not_chased(): void
    {
        $service = app(InvestigationService::class);

        $investigation = $this->open(['target_completion_date' => now()->subDay()->toDateString()]);
        $service->transition($investigation, 'reported', $this->officer);
        $service->transition($investigation->refresh(), 'under_investigation', $this->officer);
        $service->transition($investigation->refresh(), 'suspended', $this->officer);

        $this->artisan('investigations:chase');

        $this->assertSame(
            0,
            $this->notificationCount('investigation_overdue'),
            'Six months waiting on a police report is not six months of nobody working (§H.5-6).',
        );
    }

    public function test_an_approved_consequence_past_its_due_date_is_chased(): void
    {
        $investigation = $this->open();

        $subject = app(InvestigationService::class)->addSubject($investigation, [
            'subject_type' => 'staff', 'name' => 'A. Teller', 'role_in_case' => 'primary_subject',
        ], $this->officer);

        $action = app(ConsequenceService::class)->recommend($investigation, [
            'action_type' => 'query_issued',
            'investigation_subject_id' => $subject->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ], $this->officer);

        app(ConsequenceService::class)->approve($action, $this->head);

        $this->artisan('investigations:chase')->assertSuccessful();

        $this->assertSame(1, $this->notificationCount('investigation_consequence_due'));
    }

    public function test_an_implemented_consequence_is_not_chased(): void
    {
        $investigation = $this->open();

        $subject = app(InvestigationService::class)->addSubject($investigation, [
            'subject_type' => 'staff', 'name' => 'A. Teller', 'role_in_case' => 'primary_subject',
        ], $this->officer);

        $action = app(ConsequenceService::class)->recommend($investigation, [
            'action_type' => 'query_issued',
            'investigation_subject_id' => $subject->id,
            'due_date' => now()->subDays(2)->toDateString(),
        ], $this->officer);

        app(ConsequenceService::class)->implement(
            app(ConsequenceService::class)->approve($action, $this->head),
            $this->officer,
            [],
        );

        $this->artisan('investigations:chase');

        $this->assertSame(0, $this->notificationCount('investigation_consequence_due'));
    }

    // ── Assignment notifications ─────────────────────────────────────

    public function test_being_added_to_a_team_is_notified_without_leaking_a_confidential_title(): void
    {
        $investigation = $this->open(['is_confidential' => true, 'title' => 'Highly sensitive board matter']);

        app(InvestigationService::class)->assignTeamMember($investigation, $this->head, 'reviewer', $this->officer);

        $row = DB::table('notifications')
            ->where('notifiable_id', $this->head->id)
            ->where('data', 'like', '%investigation_assigned%')
            ->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString(
            'Highly sensitive board matter',
            $row->data,
            'A confidential subject line on a lock screen is not confidential.',
        );
        $this->assertStringContainsString($investigation->reference, $row->data);
    }

    private function notificationCount(string $type): int
    {
        return DB::table('notifications')->where('data', 'like', '%"'.$type.'"%')->count();
    }
}
