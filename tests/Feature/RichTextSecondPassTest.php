<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ConsequenceAction;
use App\Models\CrossBorderTransfer;
use App\Models\DataSource;
use App\Models\EntityLink;
use App\Models\ExceptionEscalation;
use App\Models\Framework;
use App\Models\Initiative;
use App\Models\Investigation;
use App\Models\InvestigationSubject;
use App\Models\MaterialityAssessment;
use App\Models\MetricBreach;
use App\Models\MetricValue;
use App\Models\MonitoringFinding;
use App\Models\MonitoringRule;
use App\Models\Objective;
use App\Models\ObjectiveMetric;
use App\Models\ObligationInstance;
use App\Models\PolicyException;
use App\Models\RegulatoryChange;
use App\Models\RiskAppetite;
use App\Models\RiskAssessment;
use App\Models\RiskTreatment;
use App\Models\SodConflictRule;
use App\Models\SpeakUpCase;
use App\Models\SustainabilityFiling;
use App\Models\SustainabilityFilingStage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorScreening;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec §9 — the second Editor.js pass.
 *
 * Thirty models gained `{field}_rich` columns. The thing worth testing is
 * not that the editor renders — it is that a document survives the trip.
 *
 * The failure this guards against is silent and expensive: swap a textarea
 * for a block editor, forget the `_rich` column or its validation rule, and
 * the page looks right, the user builds a table, presses save, and only the
 * flattened plain mirror is kept. Nothing errors. The formatting is simply
 * gone, and nobody finds out until they open the record a month later.
 */
class RichTextSecondPassTest extends TestCase
{
    use RefreshDatabase;

    /** Every (model, field) the second pass converted. */
    private const CONVERTED = [
        SpeakUpCase::class => ['description'],
        Complaint::class => ['description', 'resolution_summary'],
        ConsequenceAction::class => ['implementation_note', 'rejection_reason'],
        CrossBorderTransfer::class => ['description', 'lawful_basis_note'],
        DataSource::class => ['data_residency_note'],
        EntityLink::class => ['notes'],
        ExceptionEscalation::class => ['closure_note'],
        Framework::class => ['description'],
        Initiative::class => ['description'],
        InvestigationSubject::class => ['outcome_rationale'],
        Investigation::class => ['archive_reason'],
        MaterialityAssessment::class => ['rationale'],
        MetricBreach::class => ['action_taken'],
        MetricValue::class => ['comment'],
        MonitoringFinding::class => ['review_notes'],
        MonitoringRule::class => ['description'],
        ObjectiveMetric::class => ['note'],
        Objective::class => ['description'],
        ObligationInstance::class => ['notes'],
        PolicyException::class => ['compensating_measures', 'justification'],
        RegulatoryChange::class => ['impact_assessment', 'summary'],
        RiskAppetite::class => ['metric_definition', 'statement'],
        RiskAssessment::class => ['impact_rationale', 'likelihood_rationale'],
        RiskTreatment::class => ['acceptance_reason', 'verification_notes'],
        SodConflictRule::class => ['description'],
        SustainabilityFilingStage::class => ['note'],
        SustainabilityFiling::class => ['verification_note'],
        VendorAssessment::class => ['conclusion'],
        VendorScreening::class => ['disposition', 'summary'],
        Vendor::class => ['services_provided'],
    ];

    /** A document with a list and a table — the two the PDF has to survive. */
    private function document(): array
    {
        return [
            'time' => 0,
            'version' => '2.30.0',
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'The reconciliation did not operate for eleven days.']],
                ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Till 3', 'Till 7']]],
                ['type' => 'table', 'data' => ['content' => [['Date', 'Amount'], ['03 May', '₦120,000']]]],
            ],
        ];
    }

    public function test_every_converted_field_has_its_rich_column(): void
    {
        foreach (self::CONVERTED as $class => $fields) {
            $model = new $class;
            $table = $model->getTable();

            foreach ($fields as $field) {
                $this->assertTrue(
                    Schema::hasColumn($table, "{$field}_rich"),
                    "{$table}.{$field}_rich is missing — the editor would render and the document would be dropped on save.",
                );
            }
        }
    }

    public function test_every_converted_field_is_fillable_and_cast(): void
    {
        foreach (self::CONVERTED as $class => $fields) {
            $model = new $class;

            foreach ($fields as $field) {
                $this->assertContains(
                    "{$field}_rich",
                    $model->getFillable(),
                    $class."::{$field}_rich is not fillable, so a mass assignment would discard it.",
                );

                $this->assertSame(
                    'array',
                    $model->getCasts()["{$field}_rich"] ?? null,
                    $class."::{$field}_rich must cast to array or the document round-trips as a JSON string.",
                );
            }
        }
    }

    /**
     * The real round trip, on one model, all the way to the database and
     * back: the document is stored intact AND the plain column is derived
     * from it, because every existing reader — search, exports, the report
     * builder — still reads the plain column.
     */
    public function test_a_document_round_trips_and_derives_its_plain_mirror(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $this->actingAs($user);

        $vendor = Vendor::create([
            'tenant_id' => $tenant->id,
            'reference' => 'VND-2026-001',
            'legal_name' => 'Adeyemi Trading Ltd',
            'services_provided_rich' => $this->document(),
        ]);

        $fresh = $vendor->fresh();

        $this->assertIsArray($fresh->services_provided_rich);
        $this->assertCount(3, $fresh->services_provided_rich['blocks']);
        $this->assertSame(
            'table',
            $fresh->services_provided_rich['blocks'][2]['type'],
            'The table block must survive — §9 requires lists and tables to reach the PDF.',
        );

        $plain = (string) $fresh->services_provided;

        $this->assertNotSame('', trim($plain), 'The plain mirror must be derived, not left empty.');
        $this->assertStringContainsString('reconciliation did not operate', $plain);
        $this->assertStringContainsString('Till 3', $plain, 'List items belong in the mirror.');
    }

    /**
     * Legacy plain text must keep rendering. Existing rows have _rich NULL
     * and are never touched by the migration; the editor turns them into
     * paragraph blocks on load and the first save persists the document.
     */
    public function test_a_legacy_plain_row_is_untouched_and_still_readable(): void
    {
        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $vendor = Vendor::create([
            'tenant_id' => $tenant->id,
            'reference' => 'VND-2026-002',
            'legal_name' => 'Legacy Vendor Ltd',
            'services_provided' => "Cash-in-transit.\nATM replenishment.",
        ]);

        $fresh = $vendor->fresh();

        $this->assertNull($fresh->services_provided_rich, 'The migration must not backfill.');
        $this->assertSame("Cash-in-transit.\nATM replenishment.", $fresh->services_provided);
    }

    /**
     * A malformed document is rejected rather than stored. Blocks are user
     * input and end up in generated PDFs and exports.
     */
    public function test_a_value_that_is_not_a_document_is_discarded(): void
    {
        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $vendor = Vendor::create([
            'tenant_id' => $tenant->id,
            'reference' => 'VND-2026-003',
            'legal_name' => 'Malformed Ltd',
            'services_provided_rich' => ['not' => 'a document'],
        ]);

        $this->assertNull(
            $vendor->fresh()->services_provided_rich,
            'Anything that is not an Editor.js document must be dropped, not persisted.',
        );
    }
}
