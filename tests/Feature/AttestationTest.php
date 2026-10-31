<?php

namespace Tests\Feature;

use App\Models\Attestation;
use App\Models\Control;
use App\Models\CsaCampaign;
use App\Models\EscalationEvent;
use App\Models\EscalationMatrix;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CampaignOpenedNotification;
use App\Services\AttestationService;
use App\Services\CsaService;
use App\Services\EscalationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttestationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $staff;

    private Control $control;

    private CsaCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->staff = $this->makeUser('staff@test.local', 'Control Owner');

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-950',
            'title' => 'Code of Conduct adherence',
            'description' => 'All staff act with integrity in every market conduct matter.',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Annual',
            'status' => 'Active',
            'current_version' => 1,
            'created_by' => $this->officer->id,
        ]);

        $this->actingAs($this->officer);

        $service = app(CsaService::class);

        $this->campaign = $service->createCampaign([
            'name' => '2026 Code of Conduct attestation',
            'campaign_type' => 'attestation',
            'closes_at' => now()->addDays(14),
            'scope_definition' => [
                'attestable_type' => 'control',
                'attestable_id' => $this->control->id,
                'assignments' => [
                    ['respondent_id' => $this->staff->id],
                    ['respondent_id' => $this->officer->id],
                ],
            ],
        ], $this->officer);

        $service->syncQuestions($this->campaign->activeQuestionnaire(), [
            ['question_text' => 'I attest.', 'response_type' => 'yes_no', 'is_required' => true],
        ]);
        $service->approveCampaign($this->campaign, $this->cfh);
        $service->open($this->campaign->fresh());
        $this->campaign->refresh();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_reopening_a_campaign_does_not_invite_respondents_again(): void
    {
        Notification::fake();
        $service = app(CsaService::class);

        $service->close($this->campaign);
        $service->open($this->campaign->fresh());

        Notification::assertNotSentTo([$this->staff, $this->officer], CampaignOpenedNotification::class);
    }

    public function test_opening_a_campaign_invites_each_respondent(): void
    {
        Notification::fake();
        $service = app(CsaService::class);

        $campaign = $service->createCampaign([
            'name' => 'Q4 branch self-assessment',
            'campaign_type' => 'csa',
            'closes_at' => now()->addDays(14),
            'scope_definition' => ['assignments' => [['respondent_id' => $this->staff->id]]],
        ], $this->officer);
        $service->syncQuestions($campaign->activeQuestionnaire(), [
            ['question_text' => 'Were all reconciliations done?', 'response_type' => 'yes_no', 'is_required' => true],
        ]);
        $service->approveCampaign($campaign, $this->cfh);
        $service->open($campaign->fresh());

        $response = $campaign->responses()->where('respondent_id', $this->staff->id)->firstOrFail();
        Notification::assertSentTo($this->staff, CampaignOpenedNotification::class,
            fn ($n) => $n->toArray($this->staff)['url'] === route('csa-responses.show', $response->id));
        Notification::assertNotSentTo($this->officer, CampaignOpenedNotification::class);
    }

    public function test_attestation_snapshots_the_exact_text_and_context(): void
    {
        $this->actingAs($this->staff);

        $attestation = app(AttestationService::class)->attest($this->campaign, $this->staff, $this->control);

        $this->assertStringContainsString('integrity in every market conduct matter', $attestation->attestation_text_snapshot);
        $this->assertSame($this->staff->id, $attestation->user_id);
        $this->assertSame('web', $attestation->method);
        $this->assertNotNull($attestation->attested_at);
        $this->assertSame((string) $this->control->current_version, $attestation->version_attested);

        // Editing the control later never changes what was signed.
        $this->control->update(['description' => 'Completely different text']);
        $this->assertStringContainsString('integrity in every market conduct matter',
            $attestation->fresh()->attestation_text_snapshot);
    }

    public function test_attesting_is_idempotent_and_completes_the_campaign_response(): void
    {
        $this->actingAs($this->staff);

        $service = app(AttestationService::class);
        $first = $service->attest($this->campaign, $this->staff, $this->control);
        $second = $service->attest($this->campaign, $this->staff, $this->control);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Attestation::where('user_id', $this->staff->id)->count());

        $response = $this->campaign->responses()->where('respondent_id', $this->staff->id)->first();
        $this->assertSame('Submitted', $response->status);
    }

    public function test_attestation_requires_an_open_attestation_campaign(): void
    {
        app(CsaService::class)->close($this->campaign);

        $this->expectException(ValidationException::class);
        app(AttestationService::class)->attest($this->campaign->fresh(), $this->staff, $this->control);
    }

    public function test_outstanding_lists_only_non_attested_users(): void
    {
        $this->actingAs($this->staff);
        app(AttestationService::class)->attest($this->campaign, $this->staff, $this->control);

        $outstanding = app(AttestationService::class)->outstanding($this->campaign->fresh());

        $this->assertCount(1, $outstanding);
        $this->assertSame($this->officer->id, $outstanding[0]['user']['id']);
    }

    public function test_non_attestation_escalates_through_the_escalation_service(): void
    {
        // Campaign closed 5 days ago; rule fires at 3 days.
        $this->campaign->update(['closes_at' => now()->subDays(5)]);

        EscalationMatrix::create([
            'tenant_id' => $this->tenant->id,
            'severity' => 'Medium',
            'tier_no' => 1,
            'trigger_condition' => 'attestation_overdue',
            'days_threshold' => 3,
            'recipient_role' => 'Control Function Head',
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $fired = app(EscalationService::class)->run();

        $this->assertSame(2, $fired, 'Both outstanding users escalate');

        $events = EscalationEvent::withoutGlobalScopes()
            ->where('campaign_id', $this->campaign->id)
            ->get();

        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(
            [$this->staff->id, $this->officer->id],
            $events->pluck('subject_user_id')->all(),
        );

        // Idempotent: a second run fires nothing new.
        $this->assertSame(0, app(EscalationService::class)->run());
    }
}
