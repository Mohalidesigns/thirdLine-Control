<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\AiKnowledgeChunk;
use App\Models\Control;
use App\Models\Document;
use App\Models\SpeakUpCase;
use App\Models\Policy;
use App\Models\Risk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiService;
use App\Services\Ai\KnowledgeIndexer;
use App\Services\Ai\RetrievalService;
use Database\Seeders\AiPromptSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Permission-filtered retrieval (Part C §C.7).
 *
 * "A user must never receive a synthesised answer built from records they
 * cannot open. This is the single most important test in the phase."
 *
 * So it is tested twice: once at the retrieval boundary, and once end to end
 * through the gateway with the outbound HTTP body inspected — because a
 * filter that works in isolation and leaks into the prompt is no filter.
 */
class AiRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(AiPromptSeeder::class);

        config(['services.ollama.api_key' => 'ollama-proxy-test-0000000000000000000000']);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        // A Control Officer holds 'view risks'; a Control Owner does not.
        // That asymmetry is the point of this file: the owner can read the
        // control library and the incident log, so a blanket denial would
        // pass these tests trivially. Only the risk register is out of reach.
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);

        AiConfiguration::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'capability_key' => 'atlas.chat',
            'is_enabled' => true,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function indexControl(): Control
    {
        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-001',
            'title' => 'Dual authorisation of interbank settlement postings',
            'objective' => 'Prevent an interbank settlement posting being released by the officer who raised it.',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
        ]);

        app(KnowledgeIndexer::class)->index($control);

        return $control;
    }

    private function indexRisk(): Risk
    {
        $risk = Risk::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'RSK-001',
            'title' => 'Interbank settlement posting released by its originator',
            'description' => 'A settlement posting released without a second pair of eyes.',
            'category' => 'Operational',
            'status' => 'Active',
        ]);

        app(KnowledgeIndexer::class)->index($risk);

        return $risk;
    }

    // ── The boundary ────────────────────────────────────────────────────

    /** THE test. A chunk the user cannot open is never a candidate. */
    public function test_retrieval_never_returns_a_record_the_user_cannot_view(): void
    {
        $this->indexControl();
        $this->indexRisk();

        $retrieval = app(RetrievalService::class);

        $officerHits = $retrieval->retrieve($this->officer, 'interbank settlement posting released');
        $ownerHits = $retrieval->retrieve($this->owner, 'interbank settlement posting released');

        $this->assertTrue($this->officer->can('view risks'));
        $this->assertFalse($this->owner->can('view risks'));

        $this->assertTrue(
            $officerHits->contains(fn ($hit) => $hit['chunk']->source_type === 'risk'),
            'The officer holds view risks and should retrieve the risk.',
        );

        $this->assertFalse(
            $ownerHits->contains(fn ($hit) => $hit['chunk']->source_type === 'risk'),
            'The owner does not hold view risks and must never retrieve the risk.',
        );

        // The control is readable by both, so the owner is not simply
        // getting nothing — the filter is selective, not a blanket denial.
        $this->assertTrue(
            $ownerHits->contains(fn ($hit) => $hit['chunk']->source_type === 'control'),
            'The filter must be selective, not a blanket denial.',
        );
    }

    public function test_a_user_with_no_retrieval_permissions_retrieves_nothing(): void
    {
        $this->indexControl();

        $nobody = User::factory()->create(['email' => 'nobody@test.local', 'tenant_id' => $this->tenant->id]);

        $this->assertTrue(app(RetrievalService::class)->retrieve($nobody->fresh(), 'settlement posting')->isEmpty());
    }

    public function test_retrieval_is_tenant_scoped(): void
    {
        $this->indexControl();

        $otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $stranger = User::factory()->create(['email' => 'stranger@other.local', 'tenant_id' => $otherTenant->id]);
        $stranger->assignRole('Control Officer');

        $this->assertTrue(
            app(RetrievalService::class)->retrieve($stranger->fresh(), 'settlement posting')->isEmpty(),
            "Another tenant's records must never be retrievable.",
        );
    }

    // ── End to end, at the wire ─────────────────────────────────────────

    /**
     * The boundary test above proves the query is filtered. This one proves
     * the filtered result is what actually reaches the model — the failure
     * mode where a correct filter is bypassed somewhere between retrieval
     * and prompt assembly.
     */
    public function test_an_unreadable_record_never_reaches_the_prompt(): void
    {
        $this->indexControl();
        $this->indexRisk();

        Http::fake(['localhost:11434/*' => Http::response([
            'message' => ['role' => 'assistant', 'content' => json_encode([
                'answer' => 'Nothing to report.',
                'confidence' => 0.5,
                'insufficient_context' => false,
                'citations' => [],
            ])],
            'done' => true,
            'prompt_eval_count' => 100,
            'eval_count' => 50,
        ])]);

        app(AiGateway::class)->execute(
            app(AiService::class)->capability('atlas.chat')->request($this->owner, [
                'question' => 'What happened with the interbank settlement posting?',
            ]),
        );

        Http::assertSent(function (Request $request) {
            $body = json_encode($request->data());

            $this->assertStringNotContainsString(
                'released without a second pair of eyes',
                $body,
                'Risk register content reached the prompt for a user who cannot view risks.',
            );
            $this->assertStringNotContainsString('RSK-001', $body);

            return true;
        });
    }

    // ── Grounding and honesty ───────────────────────────────────────────

    /**
     * An empty retrieval must produce the explicit "I don't have that",
     * not a plausible answer from general knowledge.
     */
    public function test_an_empty_retrieval_is_reported_as_insufficient_context(): void
    {
        Http::fake(['localhost:11434/*' => Http::response([
            'message' => ['role' => 'assistant', 'content' => json_encode([
                // Even if the model answers confidently, the gateway knows
                // nothing was retrieved and overrides it.
                'answer' => 'Nigerian banks typically require dual authorisation.',
                'confidence' => 0.95,
                'insufficient_context' => false,
                'citations' => [],
            ])],
            'done' => true,
            'prompt_eval_count' => 100,
            'eval_count' => 50,
        ])]);

        $draft = app(AiGateway::class)->execute(
            app(AiService::class)->capability('atlas.chat')->request($this->officer, [
                'question' => 'What is our position on quantum settlement risk?',
            ]),
        );

        $this->assertTrue($draft->insufficientContext);
        $this->assertSame(0.0, $draft->confidence);
        $this->assertTrue($draft->preview()['insufficient_context']);
    }

    public function test_an_ungrounded_answer_is_heavily_discounted(): void
    {
        $this->indexControl();

        Http::fake(['localhost:11434/*' => Http::response([
            'message' => ['role' => 'assistant', 'content' => json_encode([
                'answer' => 'Everything is fine.',
                'confidence' => 1.0,
                'insufficient_context' => false,
                'citations' => [],
            ])],
            'done' => true,
            'prompt_eval_count' => 100,
            'eval_count' => 50,
        ])]);

        $draft = app(AiGateway::class)->execute(
            app(AiService::class)->capability('atlas.chat')->request($this->officer, [
                'question' => 'Tell me about the interbank settlement control.',
            ]),
        );

        // Records were available and none were cited. A model claiming 1.0
        // for an answer it grounded in nothing is the exact failure this
        // number exists to expose.
        $this->assertLessThanOrEqual(0.4, $draft->confidence);
    }

    // ── What must not be indexed ────────────────────────────────────────

    public function test_a_confidential_document_is_never_indexed(): void
    {
        $document = Document::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Board remuneration paper',
            'description' => 'Highly sensitive remuneration detail.',
            'reference' => 'DOC-001',
            'status' => 'Published',
            'is_confidential' => true,
        ]);

        app(KnowledgeIndexer::class)->index($document);

        $this->assertSame(0, AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', 'document')->where('source_id', $document->id)->count());
    }

    public function test_making_a_document_confidential_purges_its_existing_chunks(): void
    {
        $document = Document::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Vendor contract',
            'description' => 'Standard terms for the core banking vendor.',
            'reference' => 'DOC-002',
            'status' => 'Published',
            'is_confidential' => false,
        ]);

        $indexer = app(KnowledgeIndexer::class);
        $indexer->index($document);

        $this->assertGreaterThan(0, AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', 'document')->where('source_id', $document->id)->count());

        $document->update(['is_confidential' => true]);
        $indexer->index($document->refresh());

        $this->assertSame(0, AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', 'document')->where('source_id', $document->id)->count());
    }

    public function test_a_draft_policy_is_not_indexed(): void
    {
        $policy = Policy::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'policy_ref' => 'POL-001',
            'title' => 'Anti-money laundering policy',
            'description' => 'Not yet approved.',
            'status' => 'Draft',
            'version_no' => 1,
        ]);

        app(KnowledgeIndexer::class)->index($policy);

        $this->assertSame(0, AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', 'policy')->where('source_id', $policy->id)->count());
    }

    /**
     * Investigation cases are excluded structurally: they are not in the
     * source map at all, per §C.5's "Highly Restricted case content —
     * excluded from retrieval entirely".
     */
    public function test_investigation_cases_are_not_an_indexable_source(): void
    {
        $this->assertArrayNotHasKey('case', KnowledgeIndexer::sourceMap());
        $this->assertArrayNotHasKey('case', (array) config('ai.retrieval.sources'));
        $this->assertNull(KnowledgeIndexer::sourceTypeFor(new SpeakUpCase));
    }

    // ── Index maintenance ───────────────────────────────────────────────

    public function test_saving_a_record_marks_its_chunks_stale_and_reindexing_refreshes_them(): void
    {
        $control = $this->indexControl();

        $this->assertFalse(
            AiKnowledgeChunk::withoutGlobalScopes()->where('source_id', $control->id)->first()->is_stale,
        );

        // The queued listener marks stale synchronously; the queue is sync
        // in tests, so the rebuild runs immediately and clears the flag.
        $control->update(['objective' => 'A revised objective mentioning nostro reconciliation.']);

        $chunk = AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', 'control')->where('source_id', $control->id)->first();

        $this->assertFalse($chunk->is_stale);
        $this->assertStringContainsString('nostro reconciliation', $chunk->content);
    }

    public function test_deleting_a_record_purges_its_chunks(): void
    {
        $control = $this->indexControl();

        $this->assertGreaterThan(0, AiKnowledgeChunk::withoutGlobalScopes()->where('source_id', $control->id)->count());

        $control->delete();

        $this->assertSame(0, AiKnowledgeChunk::withoutGlobalScopes()
            ->where('source_type', 'control')->where('source_id', $control->id)->count());
    }

    public function test_the_reindex_command_runs_clean(): void
    {
        $this->indexControl();

        $this->artisan('ai:reindex --all')->assertSuccessful();
    }
}
