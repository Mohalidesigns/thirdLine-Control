<?php

namespace Tests\Feature;

use App\Models\ControlException;
use App\Models\Entity;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorContract;
use App\Models\VendorScreening;
use App\Services\VendorService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Phase 17.2 — third-party risk management. */
class ThirdPartyRiskTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeVendor(array $overrides = []): Vendor
    {
        return Vendor::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'TP-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'legal_name' => 'Interswitch Limited',
            'category' => 'Payment processing',
            'criticality' => 'Critical',
            'jurisdiction' => 'NG',
            'spend_currency' => 'NGN',
            'owner_id' => $this->officer->id,
            'data_access_classification' => 'personal',
            'review_frequency_months' => 12,
            'status' => 'Active',
            'created_by' => $this->officer->id,
            ...$overrides,
        ]);
    }

    private function makeAssessment(Vendor $vendor, array $overrides = []): VendorAssessment
    {
        return VendorAssessment::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendor->id,
            'reference' => 'TPA-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'assessment_type' => 'onboarding',
            'review_frequency_months' => 12,
            'status' => 'Draft',
            ...$overrides,
        ]);
    }

    public function test_the_assessor_cannot_approve_their_own_due_diligence(): void
    {
        $vendor = $this->makeVendor();
        $assessment = $this->makeAssessment($vendor);

        $vendors = app(VendorService::class);
        $vendors->submitAssessment($assessment, $this->officer, [
            'risk_score' => 62,
            'risk_band' => 'High',
            'conclusion' => 'Material outsourcing; NDPA controls adequate.',
        ]);

        // Through the service — the path a command or an import takes.
        $this->expectException(ValidationException::class);
        $vendors->approveAssessment($assessment->fresh(), $this->officer);
    }

    public function test_the_assessor_cannot_approve_their_own_due_diligence_over_http(): void
    {
        $vendor = $this->makeVendor();
        $assessment = $this->makeAssessment($vendor);

        // The Control Function Head both runs and tries to bless it, holding
        // every permission in the product (R2 — no admin bypass).
        $this->actingAs($this->cfh);

        app(VendorService::class)->submitAssessment($assessment, $this->cfh, ['risk_band' => 'High']);

        $this->post(route('vendors.assessments.approve', [$vendor, $assessment]))->assertForbidden();
        $this->assertSame('Submitted', $assessment->fresh()->status);

        // Somebody else can. The officer lacks the approval permission, so
        // a third user carries it.
        $reviewer = $this->makeUser('reviewer@test.local', 'Control Function Head');

        $this->actingAs($reviewer)
            ->post(route('vendors.assessments.approve', [$vendor, $assessment]))
            ->assertRedirect();

        $approved = $assessment->fresh();
        $this->assertSame('Approved', $approved->status);
        $this->assertSame($reviewer->id, $approved->approved_by);
    }

    public function test_approval_sets_the_expiry_from_the_review_frequency(): void
    {
        $vendor = $this->makeVendor();
        $assessment = $this->makeAssessment($vendor, ['review_frequency_months' => 6]);

        $vendors = app(VendorService::class);
        $vendors->submitAssessment($assessment, $this->officer, ['risk_band' => 'Medium']);
        $approved = $vendors->approveAssessment($assessment->fresh(), $this->cfh);

        $this->assertSame(
            now()->addMonths(6)->toDateString(),
            $approved->expires_at->toDateString(),
        );

        // The register follows the approved conclusion, so the two cannot
        // disagree.
        $this->assertSame('Medium', $vendor->fresh()->criticality);
    }

    public function test_an_expired_assessment_triggers_a_review_obligation(): void
    {
        $vendor = $this->makeVendor(['criticality' => 'Critical']);
        $assessment = $this->makeAssessment($vendor);

        $vendors = app(VendorService::class);
        $vendors->submitAssessment($assessment, $this->officer, ['risk_band' => 'Critical']);
        $vendors->approveAssessment($assessment->fresh(), $this->cfh);

        // Wind the review date into the past, as a year passing would.
        $assessment->fresh()->update(['expires_at' => now()->subDay()->toDateString()]);

        $result = $vendors->expireAssessments($this->tenant->id);

        $this->assertSame(1, $result['expired']);
        $this->assertSame(1, $result['raised']);
        $this->assertSame('Expired', $assessment->fresh()->status);
        $this->assertSame('Under Review', $vendor->fresh()->status);

        $exception = ControlException::where('source_type', 'Third Party')->firstOrFail();
        $this->assertSame('Critical', $exception->severity);
        $this->assertStringContainsString('Interswitch', $exception->title);

        // A second sweep must not raise a second exception for the same lapse.
        $again = $vendors->expireAssessments($this->tenant->id);
        $this->assertSame(0, $again['raised']);
        $this->assertSame(1, ControlException::where('source_type', 'Third Party')->count());
    }

    public function test_concentration_aggregates_across_entities_and_currencies(): void
    {
        $ng = Entity::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'ENT-001',
            'legal_name' => 'Test Bank Nigeria', 'entity_type' => 'bank', 'jurisdiction' => 'NG',
            'functional_currency' => 'NGN',
        ]);

        $gh = Entity::create([
            'tenant_id' => $this->tenant->id, 'reference' => 'ENT-002',
            'legal_name' => 'Test Bank Ghana', 'entity_type' => 'bank', 'jurisdiction' => 'GH',
            'functional_currency' => 'GHS',
        ]);

        ExchangeRate::create([
            'tenant_id' => null, 'base_currency' => 'GHS', 'quote_currency' => 'NGN',
            'rate' => '100.0000000000', 'effective_date' => now()->subDay()->toDateString(), 'source' => 'test',
        ]);

        // ₦600m in Nigeria…
        $this->makeVendor([
            'entity_id' => $ng->id, 'legal_name' => 'Interswitch Limited',
            'category' => 'Payment processing', 'annual_spend_minor' => 60_000_000_000,
            'spend_currency' => 'NGN', 'processing_country' => 'NG',
        ]);

        // …GH₵3m in Ghana, which at 100 NGN/GHS is ₦300m.
        $this->makeVendor([
            'entity_id' => $gh->id, 'legal_name' => 'Interswitch Ghana',
            'category' => 'Payment processing', 'annual_spend_minor' => 300_000_000,
            'spend_currency' => 'GHS', 'processing_country' => 'GH',
        ]);

        // …and ₦100m of unrelated spend, so the category share is not 100%.
        $this->makeVendor([
            'entity_id' => $ng->id, 'legal_name' => 'Rack Centre Limited',
            'category' => 'Data centre', 'annual_spend_minor' => 10_000_000_000,
            'spend_currency' => 'NGN', 'processing_country' => 'NG',
        ]);

        $concentration = app(VendorService::class)->concentration($this->tenant->id, 'NGN');

        // ₦600m + ₦300m + ₦100m = ₦1bn.
        $this->assertSame(100_000_000_000, $concentration['total']['amount']);
        $this->assertSame('NGN', $concentration['base_currency']);

        $payments = collect($concentration['by_category'])->firstWhere('label', 'Payment processing');
        $this->assertSame(90.0, $payments['share']);
        $this->assertSame(2, $payments['vendors']);

        $ghana = collect($concentration['by_jurisdiction'])->firstWhere('label', 'GH');
        $this->assertSame(30.0, $ghana['share']);

        // The largest single vendor is 60%. Two of the three breach the
        // default 20% threshold; the ₦100m data-centre relationship at 10%
        // does not.
        $this->assertSame('Interswitch Limited', $concentration['by_vendor'][0]['name']);
        $this->assertSame(60.0, $concentration['by_vendor'][0]['share']);
        $this->assertCount(2, $concentration['breaches']);
        $this->assertSame(10.0, collect($concentration['by_vendor'])->firstWhere('category', 'Data centre')['share']);
    }

    public function test_spend_with_no_reference_rate_is_reported_rather_than_dropped(): void
    {
        // A rate for KES was never loaded, so this vendor cannot be
        // converted. Silently excluding it would make the whole
        // concentration number wrong without saying so.
        $this->makeVendor([
            'legal_name' => 'Safaricom PLC', 'annual_spend_minor' => 50_000_000,
            'spend_currency' => 'KES', 'category' => 'Telecoms',
        ]);

        $concentration = app(VendorService::class)->concentration($this->tenant->id, 'NGN');

        $this->assertCount(1, $concentration['unconverted']);
        $this->assertSame('KES', $concentration['unconverted'][0]['currency']);
        $this->assertSame(0, $concentration['vendor_count']);
    }

    public function test_a_screening_hit_stays_open_until_a_human_dispositions_it(): void
    {
        $vendor = $this->makeVendor();

        $this->post(route('vendors.screenings.store', $vendor), [
            'screening_type' => 'sanctions',
            'result' => 'potential_match',
            'summary' => 'Name similarity against an OFAC entry.',
            'hits' => [['name' => 'Interswitch Holdings', 'source' => 'OFAC SDN']],
        ])->assertRedirect();

        $screening = VendorScreening::firstOrFail();

        $this->assertTrue($screening->needsDisposition());
        $this->assertNull($screening->cleared_at);
        $this->assertSame(1, VendorScreening::outstanding()->count());

        $this->post(route('vendors.screenings.disposition', [$vendor, $screening]), [
            'disposition' => 'False positive — different legal entity, confirmed against RC number.',
            'raise_finding' => false,
        ])->assertRedirect();

        $this->assertSame(0, VendorScreening::outstanding()->count());
        $this->assertSame($this->officer->id, $screening->fresh()->cleared_by);
    }

    public function test_a_contract_enters_its_notice_window_on_its_own_terms(): void
    {
        $vendor = $this->makeVendor();

        // 60 days out with a 90-day notice period: already inside the window.
        $inside = VendorContract::create([
            'tenant_id' => $this->tenant->id, 'vendor_id' => $vendor->id,
            'reference' => 'TPC-2026-001', 'title' => 'Switching services MSA',
            'end_date' => now()->addDays(60)->toDateString(), 'renewal_notice_days' => 90,
            'currency' => 'NGN', 'status' => 'Active',
        ]);

        // 60 days out with a 30-day notice period: not yet.
        $outside = VendorContract::create([
            'tenant_id' => $this->tenant->id, 'vendor_id' => $vendor->id,
            'reference' => 'TPC-2026-002', 'title' => 'Support addendum',
            'end_date' => now()->addDays(60)->toDateString(), 'renewal_notice_days' => 30,
            'currency' => 'NGN', 'status' => 'Active',
        ]);

        $result = app(VendorService::class)->refreshContracts($this->tenant->id);

        $this->assertSame(1, $result['alerted']);
        $this->assertSame('Expiring', $inside->fresh()->status);
        $this->assertSame('Active', $outside->fresh()->status);
    }

    public function test_a_material_outsourcing_notification_needs_its_own_permission(): void
    {
        $vendor = $this->makeVendor(['regulator_notification_required' => true]);

        $this->assertTrue($vendor->notificationOutstanding());

        // The Control Officer manages the register but does not file with
        // the regulator.
        $this->post(route('vendors.notification.store', $vendor), [
            'regulator_notified_at' => now()->toDateString(),
            'regulator_notification_ref' => 'CBN/BSD/2026/001',
        ])->assertForbidden();

        $this->actingAs($this->cfh)
            ->post(route('vendors.notification.store', $vendor), [
                'regulator_notified_at' => now()->toDateString(),
                'regulator_notification_ref' => 'CBN/BSD/2026/001',
            ])
            ->assertRedirect();

        $this->assertFalse($vendor->fresh()->notificationOutstanding());
    }

    public function test_the_register_and_concentration_pages_render(): void
    {
        $this->makeVendor(['annual_spend_minor' => 5_000_000_00]);

        $this->get(route('vendors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Vendors/Index')->has('vendors.data', 1));

        $this->get(route('vendors.concentration'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Vendors/Concentration'));
    }

    /**
     * The register's current-assessment column resolves in one query for the
     * page, not one per row — the index must not scale queries with rows
     * (R6, no N+1).
     */
    public function test_the_third_party_register_stays_within_the_query_budget(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $vendor = $this->makeVendor([
                'reference' => 'TP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'legal_name' => "Bulk vendor {$i}",
                'annual_spend_minor' => $i * 1_000_000_00,
            ]);

            $this->makeAssessment($vendor, [
                'status' => 'Approved',
                'approved_at' => now()->subMonth(),
                'expires_at' => now()->addMonths(11)->toDateString(),
            ]);
        }

        DB::enableQueryLog();
        $this->get(route('vendors.index'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $queries,
            "Third-party register ran {$queries} queries at 60 rows — budget is <40; look for an N+1.",
        );
    }
}
