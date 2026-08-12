<?php

namespace Tests\Feature;

use App\Models\AiBudget;
use App\Models\AiConfiguration;
use App\Models\AiInteraction;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiService;
use App\Services\Ai\CapabilityRequest;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\Exceptions\BudgetExceededException;
use App\Services\Ai\Exceptions\CapabilityDisabledException;
use App\Services\Ai\Exceptions\RateLimitedException;
use App\Services\Ai\Exceptions\SchemaValidationException;
use Database\Seeders\AiPromptSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The gateway pipeline (Part C §C.4).
 *
 * The HTTP client is mocked throughout and the outbound body inspected — the
 * acceptance test the phase specification asks for. Nothing here reaches an
 * Ollama server, and nothing here needs one running.
 */
class AiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private Control $control;

    private const FAKE_KEY = 'ollama-proxy-test-0000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(AiPromptSeeder::class);

        // A plausible proxy key so the "never leaks" assertions have
        // something real to hunt for. It is a literal in a test, never a
        // seeder or a committed .env (R9).
        config(['services.ollama.api_key' => self::FAKE_KEY]);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-001',
            'title' => 'Dual authorisation of GL postings',
            'objective' => 'Prevent a GL posting being released by the officer who raised it.',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);

        $this->enable('control.draft');
        $this->enable('exception.triage');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function enable(string $capability): AiConfiguration
    {
        return AiConfiguration::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'capability_key' => $capability,
            'is_enabled' => true,
        ]);
    }

    /** A well-formed reply for control.draft, in Ollama's /api/chat shape. */
    private function fakeControlDraft(array $overrides = []): array
    {
        return [
            'model' => 'granite4:micro',
            'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'title' => 'Dual authorisation of outward transfers',
                    'objective' => 'Prevent an outward transfer being released by its originator.',
                    'description' => 'The branch operations officer reviews and releases each transfer above the threshold.',
                    'type' => 'Preventive',
                    'nature' => 'Manual',
                    'frequency' => 'Daily',
                    'key_control' => true,
                    'evidence_requirements' => ['Release log extract'],
                    'test_script' => [
                        'name' => 'Outward transfer release testing',
                        'check_items' => [
                            ['assertion' => 'Each sampled transfer shows a releaser distinct from the originator.'],
                        ],
                    ],
                    'confidence' => 0.8,
                    'citations' => [],
                    ...$overrides,
                ]),
            ],
            'done' => true,
            'prompt_eval_count' => 1200,
            'eval_count' => 300,
        ];
    }

    /** A well-formed reply for exception.triage. */
    private function fakeTriage(): array
    {
        return [
            'model' => 'granite4:micro',
            'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'category' => 'Process design',
                    'probable_root_cause' => 'No named deputy for the reconciliation.',
                    'root_cause_confidence' => 0.7,
                    'recurrence_risk' => 'High',
                    'proposed_remediation' => [
                        ['action' => 'Name a deputy in the procedure.', 'owner_role' => 'Line Manager'],
                    ],
                    'confidence' => 0.75,
                    'citations' => [],
                ]),
            ],
            'done' => true,
            'prompt_eval_count' => 900,
            'eval_count' => 200,
        ];
    }

    private function draftRequest(): CapabilityRequest
    {
        return app(AiService::class)
            ->capability('control.draft')
            ->request($this->officer, ['prompt' => 'A risk of unauthorised outward transfers.']);
    }

    // ── The outbound payload ────────────────────────────────────────────

    /**
     * THE redaction test the specification asks for: mock the client,
     * execute, and assert the outbound body carries none of the patterns.
     */
    public function test_no_pii_reaches_the_outbound_request_body(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeTriage())]);

        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-2026-001',
            'source_type' => 'Manual',
            'control_id' => $this->control->id,
            'title' => 'Unauthorised mandate processed',
            'description' => 'Customer funmi.adeleke@example.com on 08031234567 with account 0123456789 '
                .'and BVN 22334455667 disputed a debit on card 4111111111111111.',
            'severity' => 'High',
            'date_raised' => now()->toDateString(),
            'status' => 'Open',
        ]);

        app(AiGateway::class)->execute(
            app(AiService::class)->capability('exception.triage')->request($this->officer, [], $exception),
        );

        Http::assertSent(function (Request $request) {
            $body = json_encode($request->data());

            foreach ([
                'funmi.adeleke@example.com', '08031234567', '0123456789',
                '22334455667', '4111111111111111',
            ] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "Outbound payload leaked: {$secret}");
            }

            return true;
        });
    }

    public function test_the_api_key_travels_only_in_the_header_and_never_in_a_stored_field(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeControlDraft())]);

        $draft = app(AiGateway::class)->execute($this->draftRequest());

        Http::assertSent(function (Request $request) {
            $this->assertSame('Bearer '.self::FAKE_KEY, $request->header('Authorization')[0] ?? null);
            $this->assertStringNotContainsString(self::FAKE_KEY, json_encode($request->data()));

            return true;
        });

        $interaction = $draft->interaction->refresh();
        $serialised = json_encode([
            $interaction->toArray(),
            $draft->preview(),
        ]);

        $this->assertStringNotContainsString(self::FAKE_KEY, $serialised);
    }

    /**
     * A provider error body can echo request headers. If one ever did, the
     * scrubber has to catch it before it lands in a stored, exportable field.
     */
    public function test_a_provider_error_echoing_the_key_is_scrubbed_before_storage(): void
    {
        Log::spy();

        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'invalid credentials: '.self::FAKE_KEY,
            ], 401),
        ]);

        try {
            app(AiGateway::class)->execute($this->draftRequest());
            $this->fail('Expected the call to fail.');
        } catch (AiUnavailableException $e) {
            // The user-facing message never carries provider detail at all.
            $this->assertStringNotContainsString(self::FAKE_KEY, $e->userMessage());
        }

        $interaction = AiInteraction::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame('Unavailable', $interaction->status);
        $this->assertStringNotContainsString(self::FAKE_KEY, (string) $interaction->error_message);
        $this->assertStringContainsString('[REDACTED]', (string) $interaction->error_message);
    }

    // ── Gates ───────────────────────────────────────────────────────────

    public function test_a_capability_with_no_configuration_row_is_off(): void
    {
        Http::fake();

        AiConfiguration::withoutGlobalScopes()->where('capability_key', 'control.draft')->delete();

        $this->expectException(CapabilityDisabledException::class);

        try {
            app(AiGateway::class)->execute($this->draftRequest());
        } finally {
            Http::assertNothingSent();
            $this->assertSame('Unavailable', AiInteraction::withoutGlobalScopes()->latest('id')->first()->status);
        }
    }

    public function test_a_user_without_the_domain_permission_is_refused(): void
    {
        Http::fake();

        $viewer = $this->makeUser('viewer@test.local', 'Executive Viewer');

        $this->expectException(CapabilityDisabledException::class);

        try {
            app(AiGateway::class)->execute(new CapabilityRequest(
                capabilityKey: 'control.draft',
                user: $viewer,
                variables: ['source_description' => 'anything'],
            ));
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * A budget stop must spend nothing — and must still leave a trace, or
     * nobody can audit why the assistant went quiet (R3).
     */
    public function test_budget_exhaustion_makes_no_call_and_still_records_the_refusal(): void
    {
        Http::fake();

        AiBudget::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'period_label' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'token_budget' => 1000,
            'tokens_used' => 1000,
            'cost_budget_minor' => 5000,
            'cost_used_minor' => 0,
            'hard_stop' => true,
        ]);

        $this->expectException(BudgetExceededException::class);

        try {
            app(AiGateway::class)->execute($this->draftRequest());
        } finally {
            Http::assertNothingSent();

            $interaction = AiInteraction::withoutGlobalScopes()->latest('id')->first();
            $this->assertSame('Budget Exceeded', $interaction->status);
            $this->assertSame(0, $interaction->input_tokens);
        }
    }

    public function test_a_soft_budget_records_the_overrun_and_lets_the_call_through(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeControlDraft())]);

        AiBudget::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'period_label' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'token_budget' => 1000,
            'tokens_used' => 1000,
            'cost_budget_minor' => 5000,
            'cost_used_minor' => 0,
            'hard_stop' => false,
        ]);

        $draft = app(AiGateway::class)->execute($this->draftRequest());

        $this->assertSame('Completed', $draft->interaction->status);
    }

    public function test_the_per_user_rate_limit_stops_the_call(): void
    {
        Http::fake();

        config(['ai.rate_limits.per_user' => 2]);

        for ($i = 0; $i < 2; $i++) {
            RateLimiter::hit('ai:user:'.$this->officer->id, 60);
        }

        $this->expectException(RateLimitedException::class);

        try {
            app(AiGateway::class)->execute($this->draftRequest());
        } finally {
            Http::assertNothingSent();
            $this->assertSame('Rate Limited', AiInteraction::withoutGlobalScopes()->latest('id')->first()->status);
        }
    }

    // ── Parsing, repair and recording ───────────────────────────────────

    public function test_the_prompt_version_is_recorded_on_every_interaction(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeControlDraft())]);

        $draft = app(AiGateway::class)->execute($this->draftRequest());

        $this->assertSame(1, $draft->interaction->prompt_version);
        $this->assertNotNull($draft->interaction->prompt_id);
        $this->assertSame('control.draft', $draft->interaction->prompt->key);
    }

    /**
     * One repair round, then fail. A half-parsed suggestion is worse than
     * none — a reviewer cannot tell which half to trust.
     */
    public function test_malformed_output_is_repaired_once_then_fails_gracefully(): void
    {
        Http::fakeSequence()
            ->push([
                'message' => ['role' => 'assistant', 'content' => 'Sorry, here is some prose instead.'],
                'done' => true,
                'prompt_eval_count' => 100,
                'eval_count' => 20,
            ])
            ->push($this->fakeControlDraft());

        $draft = app(AiGateway::class)->execute($this->draftRequest());

        $this->assertSame('Completed', $draft->interaction->status);
        // Both attempts are billed — a failed round still consumed tokens.
        $this->assertSame(1300, $draft->interaction->input_tokens);
    }

    public function test_output_that_never_validates_is_rejected_and_recorded(): void
    {
        Http::fake([
            'localhost:11434/*' => Http::response([
                'message' => ['role' => 'assistant', 'content' => '{"title": "Missing everything else"}'],
                'done' => true,
                'prompt_eval_count' => 100,
                'eval_count' => 20,
            ]),
        ]);

        $this->expectException(SchemaValidationException::class);

        try {
            app(AiGateway::class)->execute($this->draftRequest());
        } finally {
            $interaction = AiInteraction::withoutGlobalScopes()->latest('id')->first();
            $this->assertSame('Rejected by Schema', $interaction->status);
            $this->assertSame('Pending', $interaction->human_decision);
            // Billed, because the tokens were genuinely spent.
            $this->assertGreaterThan(0, $interaction->input_tokens);
        }
    }

    public function test_a_fabricated_citation_is_dropped(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeControlDraft([
            'citations' => [
                ['record_type' => 'control', 'record_id' => 999999, 'claim' => 'Invented.'],
            ],
        ]))]);

        $draft = app(AiGateway::class)->execute($this->draftRequest());

        $this->assertSame([], $draft->citations, 'A citation naming a record that was never retrieved must be dropped.');
    }

    /**
     * Every call is recorded whatever happens to it, and the redacted input
     * — not the original — is what gets stored.
     */
    public function test_the_interaction_stores_redacted_input_and_context_ids_only(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeControlDraft())]);

        $draft = app(AiGateway::class)->execute($this->draftRequest());
        $interaction = $draft->interaction->refresh();

        $this->assertNotNull($interaction->input_hash);
        $this->assertIsArray($interaction->input_redacted);
        $this->assertIsArray($interaction->retrieved_context);

        foreach ($interaction->retrieved_context as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayNotHasKey('content', $entry, 'Retrieved context must hold ids, never content.');
        }
    }

    /**
     * Optional parameters are gated on the per-model feature matrix. A
     * model absent from the matrix falls back to `default`, which sends
     * nothing optional — the safe direction.
     */
    public function test_temperature_is_not_sent_to_a_model_outside_the_feature_matrix(): void
    {
        Http::fake(['localhost:11434/*' => Http::response($this->fakeControlDraft())]);

        AiConfiguration::withoutGlobalScopes()
            ->where('capability_key', 'control.draft')
            ->update(['model' => 'some-unlisted:model', 'temperature' => 0.4]);

        app(AiGateway::class)->execute($this->draftRequest());

        Http::assertSent(function (Request $request) {
            $this->assertArrayNotHasKey('temperature', (array) ($request->data()['options'] ?? []));
            $this->assertSame('some-unlisted:model', $request->data()['model']);

            return true;
        });
    }
}
