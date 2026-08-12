<?php

namespace Tests\Feature;

use App\Models\Attestation;
use App\Models\Control;
use App\Models\CsaAnswer;
use App\Models\CsaResponse;
use App\Models\OfflineAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CsaService;
use App\Services\FeatureService;
use App\Services\OfflineSyncService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The offline outbox (15.1): ordered idempotent replay, conflicts that
 * surface instead of overwriting, and the structural rule that no
 * approval or SoD-gated transition is performable from the queue (R2).
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    private Control $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-1500',
            'title' => 'Branch cash reconciliation',
            'description' => 'Daily reconciliation of branch vault cash to the general ledger.',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $this->officer->id,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeAttestationCampaign()
    {
        $this->actingAs($this->officer);
        $service = app(CsaService::class);

        $campaign = $service->createCampaign([
            'name' => '2026 Code of Conduct',
            'campaign_type' => 'attestation',
            'closes_at' => now()->addDays(14),
            'scope_definition' => [
                'attestable_type' => 'control',
                'attestable_id' => $this->control->id,
                'assignments' => [['respondent_id' => $this->owner->id]],
            ],
        ], $this->officer);

        $service->approveCampaign($campaign, $this->cfh);
        $service->open($campaign->fresh());

        return $campaign->fresh();
    }

    private function makeOpenCsaResponse(): CsaResponse
    {
        $this->actingAs($this->officer);
        $service = app(CsaService::class);

        $campaign = $service->createCampaign([
            'name' => 'Q3 2026 CSA',
            'campaign_type' => 'csa',
            'scope_definition' => [
                'assignments' => [['respondent_id' => $this->owner->id, 'control_id' => $this->control->id]],
            ],
        ], $this->officer);

        $service->syncQuestions($campaign->activeQuestionnaire(), [
            ['question_text' => 'Is the control operating?', 'response_type' => 'yes_no', 'is_required' => true, 'weight' => 1],
        ]);

        $service->approveCampaign($campaign, $this->cfh);
        $service->open($campaign->fresh());

        return CsaResponse::withoutGlobalScopes()->where('respondent_id', $this->owner->id)->firstOrFail();
    }

    // ── Idempotent ordered replay ────────────────────────────────────

    public function test_outbox_applies_actions_and_duplicate_replay_is_idempotent(): void
    {
        $campaign = $this->makeAttestationCampaign();
        $clientId = (string) Str::uuid();

        $action = [
            'client_id' => $clientId,
            'type' => 'attestation.complete',
            'payload' => ['campaign_id' => $campaign->id, 'accepted' => true],
            'acted_at' => now()->subHour()->toIso8601String(),
        ];

        $first = $this->actingAs($this->owner)
            ->postJson(route('offline.outbox'), ['actions' => [$action]])
            ->assertOk()
            ->json('outcomes.0');

        $this->assertSame('applied', $first['status']);
        $this->assertSame(1, Attestation::withoutGlobalScopes()->count());

        // The device never got the response and replays the same action.
        $second = $this->actingAs($this->owner)
            ->postJson(route('offline.outbox'), ['actions' => [$action]])
            ->assertOk()
            ->json('outcomes.0');

        $this->assertSame('applied', $second['status']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(1, Attestation::withoutGlobalScopes()->count(), 'A replay must never apply twice.');
        $this->assertSame(1, OfflineAction::withoutGlobalScopes()->count());
    }

    public function test_outcomes_come_back_in_submission_order(): void
    {
        $campaign = $this->makeAttestationCampaign();

        $ids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $outcomes = $this->actingAs($this->owner)
            ->postJson(route('offline.outbox'), ['actions' => [
                ['client_id' => $ids[0], 'type' => 'attestation.complete', 'payload' => ['campaign_id' => $campaign->id, 'accepted' => true]],
                ['client_id' => $ids[1], 'type' => 'test.review', 'payload' => ['whatever' => 1]],
                ['client_id' => $ids[2], 'type' => 'attestation.complete', 'payload' => ['campaign_id' => $campaign->id, 'accepted' => true]],
            ]])
            ->assertOk()
            ->json('outcomes');

        $this->assertSame($ids, array_column($outcomes, 'client_id'), 'Outcomes must preserve submission order.');
    }

    // ── R2: approvals cannot come from the queue ─────────────────────

    public function test_an_offline_queued_approval_is_rejected_with_a_clear_reason(): void
    {
        foreach (['test.review', 'test.approve-rating', 'exception.verify', 'csa.review', 'submission.file'] as $type) {
            $outcome = $this->actingAs($this->cfh)
                ->postJson(route('offline.outbox'), ['actions' => [[
                    'client_id' => (string) Str::uuid(),
                    'type' => $type,
                    'payload' => ['id' => 1, 'approved' => true],
                ]]])
                ->assertOk()
                ->json('outcomes.0');

            $this->assertSame('rejected', $outcome['status'], "{$type} must not be replayable offline.");
            $this->assertSame(OfflineSyncService::OFFLINE_BLOCKED_REASON, $outcome['reason']);
        }
    }

    public function test_a_queued_action_without_permission_is_rejected_on_sync(): void
    {
        $campaign = $this->makeAttestationCampaign();

        // Executive Viewer holds no 'respond campaigns'.
        $viewer = $this->makeUser('exec@test.local', 'Executive Viewer');

        $outcome = $this->actingAs($viewer)
            ->postJson(route('offline.outbox'), ['actions' => [[
                'client_id' => (string) Str::uuid(),
                'type' => 'attestation.complete',
                'payload' => ['campaign_id' => $campaign->id, 'accepted' => true],
            ]]])
            ->assertOk()
            ->json('outcomes.0');

        $this->assertSame('rejected', $outcome['status']);
        $this->assertSame(0, Attestation::withoutGlobalScopes()->count());
    }

    // ── Conflicts surface, never overwrite ───────────────────────────

    public function test_a_conflicting_server_change_surfaces_a_prompt_and_does_not_overwrite(): void
    {
        $response = $this->makeOpenCsaResponse();
        $question = $response->questionnaire->questions()->firstOrFail();

        // What the device last saw, BEFORE the server copy moved on.
        $staleTimestamp = $response->updated_at->subMinutes(30)->toIso8601String();

        // Meanwhile the server copy changed (another device saved).
        $this->actingAs($this->owner);
        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $question->id, 'value' => 'Yes'],
        ], $this->owner);

        $outcome = $this->actingAs($this->owner)
            ->postJson(route('offline.outbox'), ['actions' => [[
                'client_id' => (string) Str::uuid(),
                'type' => 'csa.answers',
                'payload' => [
                    'response_id' => $response->id,
                    'answers' => [['question_id' => $question->id, 'value' => 'No']],
                    'submit' => false,
                    'expected_updated_at' => $staleTimestamp,
                ],
            ]]])
            ->assertOk()
            ->json('outcomes.0');

        $this->assertSame('conflict', $outcome['status']);
        $this->assertStringContainsString('server copy changed', $outcome['reason']);
        $this->assertNotNull($outcome['result']['server_state'], 'The prompt needs the server version to show.');

        $answer = CsaAnswer::where('response_id', $response->id)->where('question_id', $question->id)->first();
        $this->assertSame('Yes', $answer->answer_value, 'A conflict must never silently overwrite the server copy.');
    }

    public function test_matching_expected_timestamp_applies_cleanly(): void
    {
        $response = $this->makeOpenCsaResponse();
        $question = $response->questionnaire->questions()->firstOrFail();

        $outcome = $this->actingAs($this->owner)
            ->postJson(route('offline.outbox'), ['actions' => [[
                'client_id' => (string) Str::uuid(),
                'type' => 'csa.answers',
                'payload' => [
                    'response_id' => $response->id,
                    'answers' => [['question_id' => $question->id, 'value' => 'Yes']],
                    'submit' => false,
                    'expected_updated_at' => $response->updated_at->toIso8601String(),
                ],
            ]]])
            ->assertOk()
            ->json('outcomes.0');

        $this->assertSame('applied', $outcome['status']);
        $this->assertSame('Yes', CsaAnswer::where('response_id', $response->id)->first()->answer_value);
    }

    // ── USSD attestation (15.4, feature-flagged) ─────────────────────

    public function test_ussd_flow_attests_with_the_ussd_method(): void
    {
        $campaign = $this->makeAttestationCampaign();

        app(FeatureService::class)->set(null, 'ussd', true);
        config()->set('messaging.ussd.webhook_token', 'ussd-secret');
        $this->owner->update(['phone' => '+2348011122233']);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // Dial in → menu of outstanding attestations.
        $menu = $this->post(route('webhooks.ussd', ['token' => 'ussd-secret']), [
            'sessionId' => 's1', 'phoneNumber' => '+2348011122233', 'text' => '',
        ])->assertOk();
        $this->assertStringStartsWith('CON', $menu->getContent());

        // Pick 1 → confirm → attested via 'ussd'.
        $this->post(route('webhooks.ussd', ['token' => 'ussd-secret']), [
            'sessionId' => 's1', 'phoneNumber' => '+2348011122233', 'text' => '1*1',
        ])->assertOk()->assertSee('Attestation recorded', escape: false);

        $attestation = Attestation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ussd', $attestation->method);
        $this->assertSame($this->owner->id, $attestation->user_id);
        $this->assertSame($campaign->id, $attestation->campaign_id);
    }

    public function test_ussd_webhook_needs_the_token_and_the_feature_flag(): void
    {
        $this->makeAttestationCampaign();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // Flag off → the route does not answer at all.
        config()->set('messaging.ussd.webhook_token', 'ussd-secret');
        $this->post(route('webhooks.ussd', ['token' => 'ussd-secret']), [
            'phoneNumber' => '+2348011122233', 'text' => '',
        ])->assertNotFound();

        // Flag on, wrong token → forbidden.
        app(FeatureService::class)->set(null, 'ussd', true);
        $this->post(route('webhooks.ussd', ['token' => 'wrong']), [
            'phoneNumber' => '+2348011122233', 'text' => '',
        ])->assertForbidden();
    }

    // ── Bootstrap ────────────────────────────────────────────────────

    public function test_bootstrap_returns_the_offline_working_set(): void
    {
        $campaign = $this->makeAttestationCampaign();

        $data = $this->actingAs($this->owner)
            ->getJson(route('offline.bootstrap'))
            ->assertOk()
            ->json();

        $this->assertSame($campaign->id, $data['attestations'][0]['campaign_id']);
        $this->assertNotEmpty($data['attestations'][0]['subject_text'], 'The signable text must be readable offline.');
        $this->assertSame($this->control->id, $data['controls'][0]['id']);
    }
}
