<?php

namespace Tests\Feature;

use App\Models\ContentPack;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Services\ContentPackInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPackInstallTest extends TestCase
{
    use RefreshDatabase;

    private ContentPackInstaller $installer;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installer = app(ContentPackInstaller::class);
        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
    }

    public function test_the_shipped_packs_are_discoverable(): void
    {
        $packs = $this->installer->availablePacks();

        $this->assertArrayHasKey('COSO-IC-2013', $packs);
        $this->assertArrayHasKey('CBN-CG-2023', $packs);
        $this->assertArrayHasKey('NDPA-GAID-2025', $packs);
        $this->assertSame('1.0.0', $this->installer->latestVersion('COSO-IC-2013'));
    }

    public function test_coso_installs_its_five_components_and_seventeen_principles(): void
    {
        $this->installer->install('COSO-IC-2013');

        $framework = Framework::withoutGlobalScopes()->where('code', 'COSO-IC-2013')->firstOrFail();

        $this->assertSame('verified', $framework->verification_status);
        $this->assertSame(5, FrameworkRequirement::where('framework_id', $framework->id)->where('requirement_type', 'component')->count());
        $this->assertSame(17, FrameworkRequirement::where('framework_id', $framework->id)->where('requirement_type', 'principle')->count());

        $principle11 = FrameworkRequirement::where('framework_id', $framework->id)->where('ref_code', 'P11')->firstOrFail();
        $this->assertSame('Selects and develops general controls over technology', $principle11->title);
        $this->assertNotNull($principle11->parent_id, 'Principles hang off their component.');
    }

    public function test_installing_the_same_pack_twice_creates_nothing_new(): void
    {
        $this->installer->install('COSO-IC-2013');

        $frameworks = Framework::withoutGlobalScopes()->count();
        $requirements = FrameworkRequirement::count();

        $this->installer->install('COSO-IC-2013');

        $this->assertSame($frameworks, Framework::withoutGlobalScopes()->count());
        $this->assertSame($requirements, FrameworkRequirement::count());
        $this->assertSame(1, ContentPack::where('code', 'COSO-IC-2013')->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $result = $this->installer->install('COSO-IC-2013', null, dryRun: true);

        $this->assertFalse($result['installed']);
        $this->assertSame(0, Framework::withoutGlobalScopes()->count());
        $this->assertSame(0, ContentPack::count());
        $this->assertSame('create', $result['plan']['framework']['action']);
        $this->assertSame(22, $result['plan']['requirements']['create']);
    }

    public function test_the_diff_report_switches_to_update_once_installed(): void
    {
        $this->installer->install('COSO-IC-2013');

        $plan = $this->installer->install('COSO-IC-2013', null, dryRun: true)['plan'];

        $this->assertSame('update', $plan['framework']['action']);
        $this->assertSame(0, $plan['requirements']['create']);
        $this->assertSame(22, $plan['requirements']['update']);
    }

    public function test_an_install_never_overwrites_a_tenant_customised_obligation(): void
    {
        $this->installer->install('CBN-CG-2023');

        $shipped = RegulatoryObligation::withoutGlobalScopes()
            ->whereNull('tenant_id')->where('obligation_ref', 'CBN-CG-2023-BOARD-EVAL')->firstOrFail();

        // The tenant takes its own copy and changes the deadline.
        $customised = RegulatoryObligation::withoutGlobalScopes()->create([
            ...$shipped->only([
                'regulator_id', 'framework_id', 'requirement_id', 'title', 'description',
                'obligation_type', 'applies_to', 'trigger_type', 'frequency',
            ]),
            'tenant_id' => $this->tenant->id,
            'obligation_ref' => 'CBN-CG-2023-BOARD-EVAL',
            'due_rule' => ['type' => 'fixed_date', 'month' => 4, 'day' => 30],
            'title' => 'Board evaluation report — internal deadline',
            'verification_status' => 'unverified',
        ]);

        $result = $this->installer->install('CBN-CG-2023');

        $this->assertSame(
            ['type' => 'fixed_date', 'month' => 4, 'day' => 30],
            $customised->fresh()->due_rule,
            'The tenant’s own copy must survive a pack update untouched.',
        );

        $this->assertSame('Board evaluation report — internal deadline', $customised->fresh()->title);
        $this->assertContains('CBN-CG-2023-BOARD-EVAL', $result['plan']['tenant_overrides']['obligations']);
    }

    public function test_the_verification_summary_reports_what_is_safe_to_file(): void
    {
        $result = $this->installer->install('CBN-RBCF-2022');
        $summary = $result['plan']['verification_summary'];

        $this->assertSame('unverified', $summary['pack']);
        $this->assertGreaterThan(0, $summary['obligations']['draft'], 'The unconfirmed incident window ships as draft.');
        $this->assertSame(0, $summary['obligations']['verified']);
    }

    public function test_nigerian_packs_ship_unverified_so_nothing_can_be_filed_unchecked(): void
    {
        foreach (['CBN-CG-2023', 'FRC-NCCG-2018', 'NDPA-GAID-2025', 'NCC'] as $code) {
            $this->installer->install($code);
        }

        $this->assertSame(
            0,
            RegulatoryObligation::withoutGlobalScopes()->where('verification_status', 'verified')->count(),
            'No Nigerian obligation may claim verified status before a human confirms it against the primary document.',
        );
    }

    public function test_the_pack_records_a_checksum_and_an_audit_event(): void
    {
        $this->installer->install('COSO-IC-2013');

        $pack = ContentPack::where('code', 'COSO-IC-2013')->firstOrFail();

        $this->assertSame(64, strlen($pack->checksum));
        $this->assertNotNull($pack->installed_at);
        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => ContentPack::class,
            'entity_id' => $pack->id,
            'action' => 'content-pack-installed',
        ]);
    }

    public function test_a_mapping_only_pack_draws_edges_between_installed_frameworks(): void
    {
        $this->installer->install('COSO-IC-2013');
        $this->installer->install('ISO-27001-2022');
        $this->installer->install('CROSS-MAP');

        $coso = Framework::withoutGlobalScopes()->where('code', 'COSO-IC-2013')->firstOrFail();
        $p11 = FrameworkRequirement::where('framework_id', $coso->id)->where('ref_code', 'P11')->firstOrFail();

        $this->assertGreaterThan(0, $p11->outgoingMappings()->count());
        $this->assertSame('none', $this->installer->install('CROSS-MAP', null, dryRun: true)['plan']['framework']['action']);
    }

    public function test_an_unknown_pack_code_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->installer->install('NOT-A-PACK');
    }

    public function test_the_console_command_installs_and_reports(): void
    {
        $this->artisan('atheris:install-content-pack', ['code' => 'COSO-IC-2013'])
            ->assertSuccessful();

        $this->assertDatabaseHas('content_packs', ['code' => 'COSO-IC-2013', 'version' => '1.0.0']);
    }

    public function test_the_console_dry_run_writes_nothing(): void
    {
        $this->artisan('atheris:install-content-pack', ['code' => 'COSO-IC-2013', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('content_packs', ['code' => 'COSO-IC-2013']);
    }

    public function test_every_shipped_pack_installs_cleanly(): void
    {
        foreach (array_keys($this->installer->availablePacks()) as $code) {
            $this->installer->install($code);
        }

        $this->assertGreaterThan(30, Framework::withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, Regulator::count());
        $this->assertGreaterThan(0, RegulatoryObligation::withoutGlobalScopes()->count());
    }

    public function test_a_verified_pack_records_who_verified_it_and_when(): void
    {
        $this->installer->install('COSO-IC-2013');

        $framework = Framework::withoutGlobalScopes()->where('code', 'COSO-IC-2013')->firstOrFail();

        $this->assertSame('verified', $framework->verification_status);
        $this->assertNotEmpty($framework->verified_by, 'D.7 step 3: a verified record names its verifier.');
        $this->assertNotNull($framework->verified_at);
    }

    public function test_every_pack_that_claims_verified_carries_attribution(): void
    {
        foreach (array_keys($this->installer->availablePacks()) as $code) {
            $pack = $this->installer->read($code)['pack'];

            $statuses = array_merge(
                [$pack['verification_status'] ?? 'unverified'],
                array_column($pack['requirements'] ?? [], 'verification_status'),
                array_column($pack['obligations'] ?? [], 'verification_status'),
            );

            if (! in_array('verified', $statuses, true)) {
                continue;
            }

            $this->assertNotEmpty($pack['verified_by'] ?? null, "{$code} claims verified records without a verifier.");
            $this->assertNotEmpty($pack['verified_at'] ?? null, "{$code} claims verified records without a date.");
        }
    }

    public function test_a_pack_claiming_verified_without_attribution_will_not_install(): void
    {
        $directory = database_path('content-packs/TEST-UNATTRIBUTED');
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/1.0.0.json', json_encode([
            'code' => 'TEST-UNATTRIBUTED',
            'name' => 'Unattributed claim',
            'version' => '1.0.0',
            'verification_status' => 'verified',
            'verified_by' => '',
            'verified_at' => null,
            'framework' => ['code' => 'TEST-UNATTRIBUTED', 'name' => 'Unattributed claim'],
            'requirements' => [],
        ]));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/verified_by/');

            $this->installer->install('TEST-UNATTRIBUTED');
        } finally {
            unlink($directory.'/1.0.0.json');
            rmdir($directory);
        }
    }

    public function test_a_two_part_penalty_keeps_both_halves(): void
    {
        $this->installer->install('NCC');

        $obligation = RegulatoryObligation::withoutGlobalScopes()
            ->where('obligation_ref', 'NCC-ANNUAL-FS')->firstOrFail();

        // ₦3,000,000 on default plus ₦300,000 for each day it continues.
        $this->assertSame(300_000_00, $obligation->penalty_amount_minor);
        $this->assertSame(3_000_000_00, $obligation->penalty_fixed_amount_minor);
        $this->assertSame('per_day', $obligation->penalty_basis);
    }

    public function test_a_levy_rate_is_not_recorded_as_a_penalty(): void
    {
        $this->installer->install('NCC');

        $levy = RegulatoryObligation::withoutGlobalScopes()
            ->where('obligation_ref', 'NCC-OPERATING-LEVY')->firstOrFail();

        // 1% of net revenue is the levy itself, not the cost of missing it.
        $this->assertNull($levy->penalty_amount_minor);
        $this->assertNull($levy->penalty_basis);
        $this->assertStringContainsString('1% of net revenue', $levy->description);
    }
}
