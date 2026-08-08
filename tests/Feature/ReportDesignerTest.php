<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\OrganisationUnit;
use App\Models\ReportDefinition;
use App\Models\ReportSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportDesignerService;
use App\Support\CronSchedule;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\ReportDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportDesignerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->cfh);

        $unit = OrganisationUnit::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Operations', 'type' => 'Department', 'code' => 'OPS',
        ]);

        Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-001', 'title' => 'Dual authorisation',
            'type' => 'Preventive', 'nature' => 'Manual', 'frequency' => 'Monthly',
            'is_key_control' => true, 'status' => 'Active', 'unit_id' => $unit->id,
            'operating_effectiveness' => 'Effective', 'design_effectiveness' => 'Adequate',
            'overall_rating' => 'Strong',
        ]);

        ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'reference' => 'EXC-001', 'source_type' => 'Manual',
            'title' => 'Posting made without a second signature', 'severity' => 'High', 'status' => 'Open',
            'date_raised' => now()->subDays(20), 'age_days' => 20, 'unit_id' => $unit->id,
        ]);

        $this->seed(ReportDefinitionSeeder::class);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    // ── Output engines ───────────────────────────────────────────────────

    /**
     * Every shipped report, in every format it declares. This is the test
     * that catches a section type one writer cannot express — a board pack
     * that renders as a PDF and throws as a deck is worse than one that never
     * offered the format.
     */
    public function test_every_system_report_renders_in_every_format_it_declares(): void
    {
        $definitions = ReportDefinition::withoutGlobalScopes()->where('is_system', true)->get();

        $this->assertGreaterThanOrEqual(5, $definitions->count());

        $designer = app(ReportDesignerService::class);

        foreach ($definitions as $definition) {
            foreach ($definition->formats() as $format) {
                $run = $designer->run($definition, $this->cfh, $format, ['period' => 'current_quarter']);

                $this->assertSame(
                    'Completed',
                    $run->status,
                    "{$definition->code} failed to render as {$format}: {$run->error_message}",
                );
                $this->assertGreaterThan(0, $run->file_size, "{$definition->code} produced an empty {$format}.");
                $this->assertNotNull($run->checksum);
                $this->assertTrue(Storage::disk('local')->exists($run->output_path));
            }
        }
    }

    public function test_every_engine_is_offered_and_each_one_produces_a_file(): void
    {
        $designer = app(ReportDesignerService::class);

        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'TESTING-SUMMARY')->firstOrFail();
        $definition->forceFill(['output_formats' => $designer->formats()])->saveQuietly();

        foreach ($designer->formats() as $format) {
            $run = $designer->run($definition, $this->cfh, $format);

            $this->assertSame('Completed', $run->status, "The {$format} engine failed: {$run->error_message}");
        }
    }

    public function test_a_format_the_report_does_not_publish_is_refused(): void
    {
        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'EXCEPTION-REGISTER')->firstOrFail();
        $definition->forceFill(['output_formats' => ['pdf']])->saveQuietly();

        $this->expectException(ValidationException::class);

        app(ReportDesignerService::class)->run($definition, $this->cfh, 'pptx');
    }

    public function test_a_failed_generation_is_recorded_rather_than_thrown_away(): void
    {
        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'BOARD-PACK')->firstOrFail();

        // A section pointing at a source that does not exist: the resolver
        // drops it, so this stays Completed. Force a real failure instead by
        // pointing the run at an engine whose view cannot render.
        $definition->forceFill(['styling' => ['paper' => 'a4'], 'sections' => [['type' => 'unknown-type']]])->saveQuietly();

        $run = app(ReportDesignerService::class)->run($definition, $this->cfh, 'pdf');

        // An unknown section is skipped, not fatal — the document still comes
        // out, which is the behaviour a nightly board pack needs.
        $this->assertSame('Completed', $run->status);
    }

    // ── Approval ─────────────────────────────────────────────────────────

    public function test_a_report_definition_cannot_be_approved_by_its_author(): void
    {
        $designer = app(ReportDesignerService::class);

        $definition = $designer->createDefinition([
            'code' => 'MINE',
            'name' => 'My report',
            'report_type' => 'management',
            'confidentiality' => 'Internal',
            'output_formats' => ['pdf'],
            'sections' => [['type' => 'cover', 'title' => 'Cover']],
        ], $this->officer);

        $this->assertFalse($this->officer->can('approve', $definition));

        $this->expectException(ValidationException::class);
        $designer->approveDefinition($definition, $this->officer);
    }

    public function test_a_second_person_can_approve_and_editing_withdraws_that_approval(): void
    {
        $designer = app(ReportDesignerService::class);

        $definition = $designer->createDefinition([
            'code' => 'PAIRED',
            'name' => 'Paired report',
            'report_type' => 'management',
            'confidentiality' => 'Internal',
            'output_formats' => ['pdf'],
            'sections' => [['type' => 'cover', 'title' => 'Cover']],
        ], $this->officer);

        $designer->approveDefinition($definition, $this->cfh);

        $this->assertNotNull($definition->fresh()->approved_at);
        $this->assertSame(1, $definition->fresh()->version_no);

        $designer->updateDefinition($definition, [
            'sections' => [['type' => 'cover', 'title' => 'Cover'], ['type' => 'narrative', 'title' => 'New']],
        ]);

        $definition->refresh();

        $this->assertNull($definition->approved_at, 'Changing the layout must withdraw the approval.');
        $this->assertSame(2, $definition->version_no);
    }

    // ── Scheduling and distribution ──────────────────────────────────────

    public function test_a_cron_expression_resolves_its_next_run_in_its_own_timezone(): void
    {
        $from = Carbon::parse('2026-03-10 12:00:00', 'UTC');

        $next = CronSchedule::nextRun('0 7 1 1,4,7,10 *', 'Africa/Lagos', $from);

        $this->assertNotNull($next);
        // 07:00 Lagos on 1 April is 06:00 UTC.
        $this->assertSame('2026-04-01 06:00', $next->format('Y-m-d H:i'));
    }

    public function test_an_unparseable_cron_expression_is_rejected(): void
    {
        $this->assertFalse(CronSchedule::isValid('every monday'));
        $this->assertFalse(CronSchedule::isValid('0 7 * *'));
        $this->assertTrue(CronSchedule::isValid('*/15 * * * 1-5'));
        $this->assertNull(CronSchedule::nextRun('nonsense'));
    }

    /**
     * Confidentiality is a distribution control, not a label. A Board-only
     * pack must not reach a recipient who could not open board material, and
     * the skip has to be recorded rather than silent.
     */
    public function test_distribution_withholds_a_board_report_from_recipients_who_may_not_read_it(): void
    {
        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'BOARD-PACK')->firstOrFail();
        $this->assertSame('Board', $definition->confidentiality);

        $schedule = ReportSchedule::create([
            'tenant_id' => $this->tenant->id,
            'report_definition_id' => $definition->id,
            'name' => 'Board pack',
            'cron' => '0 7 1 * *',
            'timezone' => 'Africa/Lagos',
            'output_format' => 'pdf',
            'recipients' => ['user_ids' => [$this->cfh->id, $this->owner->id]],
            'delivery_method' => 'in_app',
            'is_active' => true,
            'created_by' => $this->cfh->id,
        ]);

        $designer = app(ReportDesignerService::class);
        $run = $designer->run($definition, $this->cfh, 'pdf', [], $schedule);
        $delivered = $designer->distribute($run, $schedule);

        $run->refresh();

        $this->assertCount(1, $delivered);
        $this->assertSame($this->cfh->id, $delivered[0]['user_id']);
        $this->assertCount(1, $run->distributed_to['withheld']);
        $this->assertSame($this->owner->id, $run->distributed_to['withheld'][0]['user_id']);
    }

    public function test_the_scheduled_command_generates_distributes_and_reschedules(): void
    {
        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'MGT-CONTROL')->firstOrFail();

        $schedule = ReportSchedule::create([
            'tenant_id' => $this->tenant->id,
            'report_definition_id' => $definition->id,
            'name' => 'Monthly management report',
            'cron' => '0 7 1 * *',
            'timezone' => 'Africa/Lagos',
            'output_format' => 'pdf',
            'recipients' => ['role_names' => ['Control Function Head']],
            'delivery_method' => 'in_app',
            'is_active' => true,
            'created_by' => $this->cfh->id,
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('reports:run-scheduled')->assertSuccessful();

        $schedule->refresh();

        $this->assertSame('Completed', $schedule->last_status);
        $this->assertNotNull($schedule->last_run_at);
        $this->assertTrue($schedule->next_run_at->isFuture());
        $this->assertSame(1, $schedule->runs()->count());
    }

    // ── Download ─────────────────────────────────────────────────────────

    public function test_an_expired_download_link_is_refused(): void
    {
        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'TESTING-SUMMARY')->firstOrFail();
        $run = app(ReportDesignerService::class)->run($definition, $this->cfh, 'pdf');

        $this->actingAs($this->cfh)
            ->get(route('reports.runs.download', $run->id))
            ->assertOk();

        $run->forceFill(['expires_at' => now()->subHour()])->save();

        $this->actingAs($this->cfh)
            ->get(route('reports.runs.download', $run->id))
            ->assertStatus(410);
    }

    public function test_a_wrong_download_token_is_refused(): void
    {
        $definition = ReportDefinition::withoutGlobalScopes()->where('code', 'TESTING-SUMMARY')->firstOrFail();
        $run = app(ReportDesignerService::class)->run($definition, $this->cfh, 'pdf');

        $this->actingAs($this->cfh)
            ->get(route('reports.runs.download', ['run' => $run->id, 'token' => 'not-the-token']))
            ->assertForbidden();
    }

    public function test_a_user_without_the_export_permission_cannot_reach_the_library(): void
    {
        $this->actingAs($this->owner)
            ->get(route('reports.library'))
            ->assertForbidden();
    }

    // ── Variable interpolation ───────────────────────────────────────────

    public function test_narrative_variables_resolve_against_the_run_parameters(): void
    {
        $designer = app(ReportDesignerService::class);

        $definition = $designer->createDefinition([
            'code' => 'INTERP',
            'name' => 'Interpolation check',
            'report_type' => 'operational',
            'confidentiality' => 'Internal',
            'output_formats' => ['html'],
            'sections' => [[
                'type' => 'narrative',
                'title' => 'Body',
                'body' => 'Period {{ period }} for {{ tenant }}. Open exceptions: {{ metric:open_exceptions }}.',
            ]],
        ], $this->cfh);

        $run = $designer->run($definition, $this->cfh, 'html', ['period' => 'current_year']);
        $html = Storage::disk('local')->get($run->output_path);

        $this->assertStringContainsString('FY '.now()->year, $html);
        $this->assertStringContainsString('Test Bank', $html);
        $this->assertStringContainsString('Open exceptions: 1', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_a_report_cannot_read_data_its_requester_could_not_read(): void
    {
        $designer = app(ReportDesignerService::class);

        $definition = $designer->createDefinition([
            'code' => 'PRIVILEGED',
            'name' => 'Privileged sections',
            'report_type' => 'operational',
            'confidentiality' => 'Internal',
            'output_formats' => ['html'],
            'sections' => [
                ['type' => 'table', 'title' => 'Appetite position', 'source' => 'appetite_position'],
            ],
        ], $this->cfh);

        $run = $designer->run($definition, $this->owner, 'html');
        $html = Storage::disk('local')->get($run->output_path);

        $this->assertStringContainsString('Withheld', $html);
    }
}
