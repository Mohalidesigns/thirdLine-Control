<?php

namespace Database\Seeders;

use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\IntegrationConfig;
use App\Models\OrganisationUnit;
use App\Models\RetentionPolicy;
use App\Models\SpotCheck;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Test-fixture seeder for the end-to-end QA run. NOT part of the product
 * seed — run explicitly, after DatabaseSeeder, against the isolated e2e
 * database only:
 *
 *   DB_DATABASE=atheris_control_e2e php artisan db:seed --class=E2ETestSeeder --force
 *
 * It provides only what the test plan requires and the product seed does
 * not: fixtures for the modules that seed at zero rows, and a second
 * tenant without which no isolation test can run at all.
 *
 * Idempotent — safe to re-run. Every value is obviously synthetic and no
 * fixture contains real personal data; the "PII" evidence fixture carries
 * a classification flag and invented digits, not a real BVN or account.
 *
 * Fixtures provided
 *   TC-01-03, TC-02-05  deactivated user
 *   TC-18               ThirdLine integration config with a known API key
 *   TC-09               spot checks in each lifecycle state, with findings
 *   TC-11               compensating controls, incl. one expiring
 *   TC-13               evidence: clean, PII-classified, legal-hold, and
 *                       one already past its retention expiry
 *   TC-02-07, TC-New-04 a second tenant with its own users, unit, control
 *                       and exception, for isolation and IDOR testing
 */
class E2ETestSeeder extends Seeder
{
    /**
     * Plaintext inbound API key for /api/v1. Fixed so the run is
     * reproducible; only ever valid in the e2e database.
     */
    public const THIRDLINE_API_KEY = 'e2e-thirdline-test-key-DO-NOT-USE-IN-PRODUCTION';

    /** Second tenant, used only to prove isolation. */
    public const OTHER_TENANT_NAME = 'Rival Bank Plc (E2E ISOLATION FIXTURE)';

    public const OTHER_TENANT_ACCOUNTS = [
        'other_cfh' => 'cfh@othertenant.test',
        'other_owner' => 'owner@othertenant.test',
    ];

    private Tenant $tenant;

    public function run(): void
    {
        $tenant = Tenant::withoutGlobalScopes()->first();

        if (! $tenant) {
            $this->command?->error('No tenant found — run the product seed first.');

            return;
        }

        $this->tenant = $tenant;

        $this->deactivatedUser();
        $this->thirdLineConfig();
        $this->spotChecksAndFindings();
        $this->compensatingControls();
        $this->evidenceFixtures();
        $this->secondTenant();

        $this->report();
    }

    // -----------------------------------------------------------------
    // TC-01-03 / TC-02-05
    // -----------------------------------------------------------------

    private function deactivatedUser(): void
    {
        User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'inactive@secondline.test'],
            [
                'tenant_id' => $this->tenant->id,
                'name' => 'Ada Deactivated (TEST)',
                'position' => 'Former Control Officer',
                'unit_id' => $this->unit()?->id,
                'password' => Hash::make('password'),
                'is_active' => false,
                'email_verified_at' => now(),
            ]
        );
    }

    // -----------------------------------------------------------------
    // TC-18
    // -----------------------------------------------------------------

    private function thirdLineConfig(): void
    {
        IntegrationConfig::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'target_system' => 'ThirdLine'],
            [
                'base_url' => 'http://127.0.0.1:9999/thirdline-stub',
                'auth_type' => 'api_key',
                'inbound_key_hash' => hash('sha256', self::THIRDLINE_API_KEY),
                'sync_direction' => 'Bidirectional',
                'master_system' => 'SecondLine',
                'conflict_rule' => 'last_approved_at_wins',
                'is_active' => true,
            ]
        );
    }

    // -----------------------------------------------------------------
    // TC-09 — spot checks in every lifecycle state, with findings
    // -----------------------------------------------------------------

    private function spotChecksAndFindings(): void
    {
        $controls = Control::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('status', 'Active')
            ->take(3)->get();

        if ($controls->isEmpty()) {
            $this->command?->warn('No active controls — skipping spot check fixtures.');

            return;
        }

        $conductor = $this->user('officer1@secondline.test');
        $responsible = $this->user('owner1@secondline.test');

        // One per lifecycle state, so illegal-transition tests have a
        // subject at every point in the machine.
        $states = [
            'Draft' => ['E2E draft spot check — branch cash counts', false],
            'In Progress' => ['E2E in-progress spot check — dormant account reactivation', false],
            'Completed' => ['E2E completed spot check — teller override review', true],
            'Report Issued' => ['E2E issued spot check — vault dual control', true],
        ];

        foreach ($states as $status => [$title, $surprise]) {
            // The reference is generated, and generating it again on a
            // re-run would collide with the row's own value. Assign it
            // only when the row is first created.
            $existing = SpotCheck::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant->id)
                ->where('title', $title)
                ->first();

            $spotCheck = SpotCheck::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'title' => $title],
                [
                    'reference' => $existing?->reference ?? SpotCheck::nextReference('SPC'),
                    'scope_description' => 'Synthetic E2E fixture. Scope covers one unit for one period.',
                    'unit_id' => $this->unit()?->id,
                    'control_ids' => $controls->pluck('id')->take(2)->values()->all(),
                    'is_surprise' => $surprise,
                    'conducted_by' => $conductor?->id,
                    'date_conducted' => now()->subDays(5)->toDateString(),
                    'status' => $status,
                    'report_issued_at' => $status === 'Report Issued' ? now()->subDay() : null,
                ]
            );

            // Findings only where the lifecycle would have produced them.
            if (in_array($status, ['In Progress', 'Completed', 'Report Issued'], true)) {
                foreach ([['High', 'Overrides were not independently reviewed.'], ['Low', 'Register not initialled on two days in the sample.']] as $i => [$severity, $observation]) {
                    Finding::withoutGlobalScopes()->updateOrCreate(
                        ['spot_check_id' => $spotCheck->id, 'observation' => $observation],
                        [
                            'control_id' => $controls[$i % $controls->count()]->id,
                            'severity' => $severity,
                            'risk_implication' => 'Synthetic E2E fixture — risk implication text.',
                            'recommendation' => 'Synthetic E2E fixture — recommendation text.',
                            'management_response' => $status === 'Report Issued' ? 'Accepted; action agreed.' : null,
                            'agreed_action' => $status === 'Report Issued' ? 'Reinstate independent review by month end.' : null,
                            'responsible_party_id' => $responsible?->id,
                            'target_date' => now()->addDays(21)->toDateString(),
                        ]
                    );
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // TC-11 — compensating controls, including one due to expire
    // -----------------------------------------------------------------

    private function compensatingControls(): void
    {
        $exceptions = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->take(3)->get();

        if ($exceptions->isEmpty()) {
            $this->command?->warn('No open exceptions — skipping compensating control fixtures.');

            return;
        }

        $owner = $this->user('owner1@secondline.test');
        $cfh = $this->user('cfh@secondline.test');

        $rows = [
            // status, is_temporary, effective_to, approved
            ['Proposed', true, now()->addMonths(3), false, 'E2E proposed compensating control — manual daily reconciliation'],
            ['Active', true, now()->subDay(), true, 'E2E expiring compensating control — supervisory sign-off (past end date)'],
            ['Active', false, null, true, 'E2E permanent compensating control — system-enforced dual authorisation'],
        ];

        foreach ($rows as $i => [$status, $temporary, $effectiveTo, $approved, $description]) {
            $exception = $exceptions[$i % $exceptions->count()];

            CompensatingControl::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'description' => $description],
                [
                    'exception_id' => $exception->id,
                    'primary_control_id' => $exception->control_id,
                    'owner_id' => $owner?->id,
                    'is_temporary' => $temporary,
                    'effective_from' => now()->subMonth()->toDateString(),
                    'effective_to' => $effectiveTo?->toDateString(),
                    'residual_exposure_note' => 'Does not cover intra-day exposure between reconciliation runs.',
                    'status' => $status,
                    'approved_by' => $approved ? $cfh?->id : null,
                    'approved_at' => $approved ? now()->subWeeks(2) : null,
                ]
            );
        }
    }

    // -----------------------------------------------------------------
    // TC-13 — evidence, retention, PII classification, legal hold
    // -----------------------------------------------------------------

    private function evidenceFixtures(): void
    {
        $instance = TestInstance::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->first();
        $exception = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->first();

        if (! $instance || ! $exception) {
            $this->command?->warn('No test instance or exception — skipping evidence fixtures.');

            return;
        }

        $uploader = $this->user('officer1@secondline.test');
        $general = RetentionPolicy::withoutGlobalScopes()->where('is_default', true)->first();
        $pii = RetentionPolicy::withoutGlobalScopes()->where('evidence_class', 'Customer Personal Data')->first();

        $rows = [
            [
                'name' => 'e2e-clean-extract.csv',
                'body' => "date,reference,amount\n2026-08-01,TXN-0001,15000.00\n2026-08-02,TXN-0002,42750.50\n",
                'mime' => 'text/csv',
                'linked' => [TestInstance::class, $instance->id],
                'pii' => false,
                'categories' => null,
                'policy' => $general,
                'expiry' => now()->addMonths($general?->retention_months ?? 72),
                'hold' => false,
                'holdReason' => null,
            ],
            [
                'name' => 'e2e-pii-sample.csv',
                'body' => "synthetic_account,synthetic_bvn,name\n0000000001,00000000001,SYNTHETIC TEST SUBJECT ONE\n",
                'mime' => 'text/csv',
                'linked' => [TestInstance::class, $instance->id],
                'pii' => true,
                'categories' => ['Account numbers', 'BVN', 'Names & addresses'],
                'policy' => $pii,
                'expiry' => now()->addMonths($pii?->retention_months ?? 60),
                'hold' => false,
                'holdReason' => null,
            ],
            [
                'name' => 'e2e-legal-hold-memo.txt',
                'body' => "Synthetic E2E fixture. Retained under legal hold for an open matter.\n",
                'mime' => 'text/plain',
                'linked' => [ControlException::class, $exception->id],
                'pii' => false,
                'categories' => null,
                'policy' => $general,
                // Deliberately already expired: disposal must NOT touch it
                // while the hold is on (FR-9.7).
                'expiry' => now()->subMonths(2),
                'hold' => true,
                'holdReason' => 'Subject to an open regulatory examination (synthetic).',
            ],
            [
                'name' => 'e2e-expired-no-hold.txt',
                'body' => "Synthetic E2E fixture. Past retention expiry, no legal hold.\n",
                'mime' => 'text/plain',
                'linked' => [ControlException::class, $exception->id],
                'pii' => false,
                'categories' => null,
                'policy' => $general,
                // The disposal sweep SHOULD pick this one up.
                'expiry' => now()->subMonth(),
                'hold' => false,
                'holdReason' => null,
            ],
        ];

        foreach ($rows as $row) {
            $path = 'evidence/e2e/'.$row['name'];
            Storage::disk('local')->put($path, $row['body']);

            Evidence::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'file_name' => $row['name']],
                [
                    'linked_type' => $row['linked'][0],
                    'linked_id' => $row['linked'][1],
                    'storage_path' => $path,
                    'mime_type' => $row['mime'],
                    'file_size' => strlen($row['body']),
                    'checksum' => hash('sha256', $row['body']),
                    'contains_personal_data' => $row['pii'],
                    'personal_data_categories' => $row['categories'],
                    'classification' => $row['pii'] ? 'Confidential' : 'Internal',
                    'retention_policy_id' => $row['policy']?->id,
                    'retention_expiry_date' => $row['expiry']->toDateString(),
                    'legal_hold' => $row['hold'],
                    'legal_hold_reason' => $row['holdReason'],
                    'uploaded_by' => $uploader?->id,
                    'uploaded_at' => now()->subMonths(3),
                ]
            );
        }
    }

    // -----------------------------------------------------------------
    // TC-02-07 / TC-New-04 — a second tenant
    //
    // Without this, no cross-tenant test can run: a single-tenant database
    // cannot distinguish "isolation works" from "there is nothing to leak".
    // -----------------------------------------------------------------

    private function secondTenant(): void
    {
        $other = Tenant::withoutGlobalScopes()->updateOrCreate(
            ['name' => self::OTHER_TENANT_NAME],
            [
                'licence_key' => 'E2E-ISOLATION-FIXTURE-0000',
                'data_residency' => 'NG',
                'status' => 'active',
                'locale' => 'en',
                'timezone' => 'Africa/Lagos',
                'currency' => 'NGN',
                'date_format' => 'd/m/Y',
                'fiscal_year_start_month' => 1,
            ]
        );

        $unit = OrganisationUnit::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $other->id, 'name' => 'Rival Bank — Head Office'],
            ['type' => 'Head Office', 'code' => 'RB-HO']
        );

        $users = [
            self::OTHER_TENANT_ACCOUNTS['other_cfh'] => ['Rival CFH (TEST)', 'Control Function Head'],
            self::OTHER_TENANT_ACCOUNTS['other_owner'] => ['Rival Owner (TEST)', 'Control Owner'],
        ];

        $created = [];

        foreach ($users as $email => [$name, $role]) {
            $user = User::withoutGlobalScopes()->updateOrCreate(
                ['email' => $email],
                [
                    'tenant_id' => $other->id,
                    'name' => $name,
                    'position' => $role,
                    'unit_id' => $unit->id,
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$role]);
            $created[$role] = $user;
        }

        $control = Control::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $other->id, 'title' => 'Rival Bank control — MUST NEVER BE VISIBLE TO TENANT 1'],
            [
                'control_ref' => 'RB-CTRL-001',
                'description' => 'Isolation fixture. Any appearance of this record in a tenant-1 context is a cross-tenant leak.',
                'objective' => 'Prove tenant isolation.',
                'type' => 'Preventive',
                'nature' => 'Manual',
                'frequency' => 'Monthly',
                'status' => 'Active',
                'owner_id' => $created['Control Owner']->id,
                'unit_id' => $unit->id,
            ]
        );

        ControlException::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $other->id, 'title' => 'Rival Bank exception — MUST NEVER BE VISIBLE TO TENANT 1'],
            [
                'reference' => 'RB-EXC-001',
                'source_type' => 'Manual',
                'control_id' => $control->id,
                'description' => 'Isolation fixture.',
                'severity' => 'Critical',
                'owner_id' => $created['Control Owner']->id,
                'unit_id' => $unit->id,
                'raised_by' => $created['Control Function Head']->id,
                'date_raised' => now()->toDateString(),
                'target_closure_date' => now()->addDays(7)->toDateString(),
                'status' => 'Remediated',
            ]
        );
    }

    // -----------------------------------------------------------------

    private function unit(): ?OrganisationUnit
    {
        return OrganisationUnit::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->first();
    }

    private function user(string $email): ?User
    {
        return User::withoutGlobalScopes()->where('email', $email)->first();
    }

    private function report(): void
    {
        $other = Tenant::withoutGlobalScopes()->where('name', self::OTHER_TENANT_NAME)->first();

        $this->command?->info('E2E fixtures seeded.');
        $this->command?->line('  deactivated user     inactive@secondline.test');
        $this->command?->line('  ThirdLine X-Api-Key  '.self::THIRDLINE_API_KEY);
        $this->command?->line('  spot checks          '.SpotCheck::withoutGlobalScopes()->count().' (findings: '.Finding::withoutGlobalScopes()->count().')');
        $this->command?->line('  compensating         '.CompensatingControl::withoutGlobalScopes()->count());
        $this->command?->line('  evidence             '.Evidence::withoutGlobalScopes()->count()
            .' (PII: '.Evidence::withoutGlobalScopes()->where('contains_personal_data', true)->count()
            .', legal hold: '.Evidence::withoutGlobalScopes()->where('legal_hold', true)->count()
            .', past expiry: '.Evidence::withoutGlobalScopes()->whereDate('retention_expiry_date', '<', now())->count().')');
        $this->command?->line('  second tenant        #'.($other?->id ?? '?').' '.self::OTHER_TENANT_NAME);
    }
}
