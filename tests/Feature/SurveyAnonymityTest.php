<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\CsaCampaign;
use App\Models\CsaResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CsaService;
use App\Services\SurveyService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SurveyAnonymityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $respondent;

    private CsaCampaign $survey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->respondent = $this->makeUser('staff@test.local', 'Control Owner');

        $this->actingAs($this->officer);

        $service = app(CsaService::class);

        $this->survey = $service->createCampaign([
            'name' => 'Risk culture survey 2026',
            'campaign_type' => 'survey',
            'is_anonymous' => true,
            'scope_definition' => ['invited_count' => 120],
        ], $this->officer);

        $service->syncQuestions($this->survey->activeQuestionnaire(), [
            ['question_text' => 'I feel safe reporting a control failure.', 'response_type' => 'scale_1_5', 'is_required' => true],
            ['question_text' => 'Anything else?', 'response_type' => 'free_text', 'is_required' => false],
        ]);

        $service->approveCampaign($this->survey, $this->cfh);
        $service->open($this->survey->fresh());
        $this->survey->refresh();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_opening_an_anonymous_survey_creates_no_assigned_response_rows(): void
    {
        $this->assertSame(0, $this->survey->responses()->count());
    }

    public function test_anonymous_response_row_stores_no_identifying_data(): void
    {
        $this->actingAs($this->respondent);

        $questions = $this->survey->activeQuestionnaire()->questions()->get();

        app(SurveyService::class)->submitAnonymous($this->survey, [
            ['question_id' => $questions[0]->id, 'value' => 4],
            ['question_id' => $questions[1]->id, 'value' => 'More training please'],
        ]);

        // Assert on the RAW ROW, not the model — nothing identifying at rest.
        $row = DB::table('csa_responses')->where('campaign_id', $this->survey->id)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->respondent_id, 'respondent_id must be null on an anonymous response');
        $this->assertNull($row->entity_id, 'entity_id could narrow identity and must be null');
        $this->assertNull($row->reviewed_by);
        $this->assertSame('Submitted', $row->status);
    }

    public function test_anonymous_response_audit_trail_carries_no_user_ip_or_agent(): void
    {
        $this->actingAs($this->respondent);

        $questions = $this->survey->activeQuestionnaire()->questions()->get();

        $response = app(SurveyService::class)->submitAnonymous($this->survey, [
            ['question_id' => $questions[0]->id, 'value' => 2],
        ]);

        $rows = AuditTrail::query()
            ->where('entity_type', (new CsaResponse)->getMorphClass())
            ->where('entity_id', $response->id)
            ->get();

        $this->assertNotEmpty($rows, 'The event itself is still audited (R3)');

        foreach ($rows as $row) {
            $this->assertNull($row->user_id, 'Audit rows must not attribute an anonymous response');
            $this->assertNull($row->ip_address);
            $this->assertNull($row->user_agent);
        }
    }

    public function test_no_role_can_read_an_anonymous_response_row(): void
    {
        $this->actingAs($this->respondent);

        $questions = $this->survey->activeQuestionnaire()->questions()->get();

        $response = app(SurveyService::class)->submitAnonymous($this->survey, [
            ['question_id' => $questions[0]->id, 'value' => 1],
        ]);

        foreach ([$this->cfh, $this->officer, $this->respondent] as $user) {
            $this->assertFalse($user->can('view', $response->fresh()),
                "{$user->email} must not be able to open an anonymous response");
        }
    }

    public function test_named_campaign_still_records_the_respondent(): void
    {
        $service = app(CsaService::class);

        $this->actingAs($this->officer);

        $named = $service->createCampaign([
            'name' => 'Named survey',
            'campaign_type' => 'survey',
            'is_anonymous' => false,
            'scope_definition' => ['assignments' => [['respondent_id' => $this->respondent->id]]],
        ], $this->officer);

        $service->syncQuestions($named->activeQuestionnaire(), [
            ['question_text' => 'OK?', 'response_type' => 'yes_no', 'is_required' => true],
        ]);
        $service->approveCampaign($named, $this->cfh);
        $service->open($named->fresh());

        $response = $named->responses()->first();
        $this->assertSame($this->respondent->id, $response->respondent_id);
    }
}
