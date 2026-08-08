<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\DataSnapshot;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use App\Models\MonitoringRule;
use App\Models\SodConflictRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MonitoringService;
use App\Services\SnapshotService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * A demoable continuous-monitoring position for a Nigerian bank (12.x).
 *
 * Three sources — a Finacle extract, an Entra directory pull and a
 * manually uploaded AML alert sheet — feeding six rules across six of the
 * eleven rule types, with real fixture rows written into real snapshots
 * so the runs, findings and exceptions on screen are genuine output
 * rather than seeded pretence.
 *
 * The Finacle source is deliberately left *paused by the circuit
 * breaker*, because a dead feed is the most common real failure and the
 * dashboard should show one.
 */
class Phase12DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrFail();
        $tid = $tenant->id;

        $user = fn (string $email) => User::withoutGlobalScopes()->where('email', $email)->first();

        $cfh = $user('cfh@secondline.test');
        $officer1 = $user('officer1@secondline.test');
        $officer2 = $user('officer2@secondline.test');
        $admin = $user('admin@secondline.test');

        if (! $cfh || ! $officer1 || ! $officer2 || ! $admin) {
            return;
        }

        // The services stamp tenant_id and audit rows from the acting user.
        Auth::login($officer1);

        $control = fn (string $like) => Control::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->where('title', 'like', "%{$like}%")
            ->first();

        $glControl = $control('authoris') ?? Control::withoutGlobalScopes()->where('tenant_id', $tid)->first();
        $accessControl = $control('access') ?? $glControl;

        // ── Sources ──────────────────────────────────────────────────
        $finacle = $this->source($tid, [
            'name' => 'Finacle core banking (read-only)',
            'source_type' => 'database',
            'system_category' => 'core_banking',
            'vendor_key' => 'finacle',
            'auth_type' => 'basic',
            'connection_config' => ['driver' => 'oracle', 'host' => 'finacle-rpt.bank.internal', 'database' => 'FCRPT', 'schema' => 'TBAADM'],
            'credentials' => json_encode(['username' => 'svc_secondline', 'password' => 'seeded-placeholder-not-a-real-secret']),
            'data_residency_note' => 'Extracts stay on the Lagos data-centre volume; nothing leaves NG.',
            'owner_id' => $admin->id,
            'created_by' => $officer1->id,
            'failure_threshold' => 3,
        ], $cfh);

        $entra = $this->source($tid, [
            'name' => 'Microsoft Entra ID directory',
            'source_type' => 'rest_api',
            'system_category' => 'identity',
            'vendor_key' => 'entra',
            'auth_type' => 'oauth2',
            'connection_config' => ['tenant_id' => '00000000-0000-0000-0000-000000000000', 'base_url' => 'https://graph.microsoft.com/v1.0'],
            'credentials' => json_encode(['client_id' => 'seeded-client-id', 'client_secret' => 'seeded-placeholder-not-a-real-secret']),
            'data_residency_note' => 'Directory metadata only; no customer data.',
            'owner_id' => $admin->id,
            'created_by' => $officer1->id,
        ], $cfh);

        $upload = $this->source($tid, [
            'name' => 'AML alert workbook (monthly upload)',
            'source_type' => 'file_upload',
            'system_category' => 'spreadsheet',
            'vendor_key' => 'custom',
            'auth_type' => 'none',
            'connection_config' => [],
            'data_residency_note' => 'Uploaded by the AML unit; stored in-country.',
            'owner_id' => $officer2->id,
            'created_by' => $officer1->id,
        ], $cfh);

        // ── Datasets ─────────────────────────────────────────────────
        $postings = $this->dataset($finacle, [
            'name' => 'gl_postings',
            'description' => 'General ledger postings with maker, checker and authorisation timestamps.',
            'extraction_config' => ['table' => 'GENERAL_ACCT_MAST_TRAN'],
            'primary_key_fields' => ['transaction_id'],
            'incremental_field' => 'posted_at',
            'retention_days' => 400,
            'pii_classification' => 'internal',
        ]);

        $entitlements = $this->dataset($finacle, [
            'name' => 'user_entitlements',
            'description' => 'One row per user per Finacle menu entitlement — the SoD feed.',
            'extraction_config' => ['table' => 'SMTB_USER_ROLE'],
            'primary_key_fields' => ['subject_identifier', 'function'],
            'retention_days' => 730,
            'pii_classification' => 'personal',
            'pii_fields' => ['subject_name'],
        ]);

        $directory = $this->dataset($entra, [
            'name' => 'entra_users',
            'description' => 'Directory users with MFA registration and last sign-in.',
            'extraction_config' => ['endpoint' => '/users', 'data_path' => 'value'],
            'primary_key_fields' => ['userPrincipalName'],
            'retention_days' => 365,
            'pii_classification' => 'personal',
            'pii_fields' => ['userPrincipalName', 'displayName'],
        ]);

        $alerts = $this->dataset($upload, [
            'name' => 'aml_alerts',
            'description' => 'Monthly AML alert extract awaiting disposition.',
            'extraction_config' => ['upload_path' => 'ccm/uploads/aml-alerts.xlsx'],
            'primary_key_fields' => ['alert_id'],
            'retention_days' => 1825,
            'pii_classification' => 'sensitive_personal',
            'pii_fields' => ['bvn', 'account_number'],
        ]);

        // ── Fixture snapshots ────────────────────────────────────────
        // Written straight to the snapshot store so the demo runs produce
        // real findings against real rows.
        $this->snapshot($postings, $this->postingRows());
        $this->snapshot($entitlements, $this->entitlementRows());
        $this->snapshot($directory, $this->directoryRows());
        $this->snapshot($alerts, $this->alertRows());

        // ── SoD conflict matrix ──────────────────────────────────────
        foreach ([
            ['Post and authorise a GL entry', 'finacle', 'TXN_ENTRY', 'TXN_AUTH', 'create_approve', 'Critical'],
            ['Maintain customer static data and authorise transactions', 'finacle', 'CUST_MAINT', 'TXN_AUTH', 'maintain_reconcile', 'High'],
            ['Create a vendor and release a payment', 'finacle', 'VENDOR_CREATE', 'PAYMENT_RELEASE', 'create_pay', 'Critical'],
            ['Grant privileged access and approve access requests', 'entra', 'Privileged Role Administrator', 'User Administrator', 'request_authorise', 'High'],
        ] as [$name, $system, $a, $b, $type, $risk]) {
            SodConflictRule::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tid, 'system_key' => $system, 'function_a' => $a, 'function_b' => $b],
                [
                    'name' => $name,
                    'conflict_type' => $type,
                    'risk_level' => $risk,
                    'mitigating_control_id' => $type === 'create_approve' ? $glControl?->id : null,
                    'is_active' => true,
                ],
            );
        }

        // ── Monitoring rules ─────────────────────────────────────────
        $monitoring = app(MonitoringService::class);

        $rules = [
            [
                'name' => 'Same-day create-and-approve on GL postings',
                'description' => 'Maker-checker did not operate: the posting was created and authorised by the same user, '
                    .'or authorised within a minute of creation.',
                'rule_type' => 'pattern',
                'control_id' => $glControl?->id,
                'dataset_ids' => [$postings->id],
                'rule_definition' => [
                    'pattern' => 'same_day_maker_checker',
                    'maker_field' => 'maker',
                    'checker_field' => 'checker',
                    'created_at_field' => 'posted_at',
                    'approved_at_field' => 'authorised_at',
                    'identifier_field' => 'transaction_id',
                    'min_seconds' => 60,
                ],
                'severity' => 'Critical',
                'frequency' => 'Daily',
            ],
            [
                'name' => 'Postings above the branch authorisation limit',
                'description' => 'Any single posting above ₦5,000,000 from a branch teller.',
                'rule_type' => 'exception_list',
                'control_id' => $glControl?->id,
                'dataset_ids' => [$postings->id],
                'rule_definition' => [
                    'conditions' => [
                        ['field' => 'amount', 'operator' => 'gt', 'value' => 5000000],
                        ['field' => 'channel', 'operator' => 'eq', 'value' => 'BRANCH'],
                    ],
                    'match' => 'all',
                    'identifier_field' => 'transaction_id',
                ],
                'severity' => 'High',
                'frequency' => 'Daily',
            ],
            [
                'name' => 'Total suspense balance within limit',
                'description' => 'Aggregate suspense exposure must stay at or below ₦10,000,000.',
                'rule_type' => 'threshold',
                'control_id' => $glControl?->id,
                'dataset_ids' => [$postings->id],
                'rule_definition' => [
                    'field' => 'amount',
                    'aggregate' => 'sum',
                    'operator' => 'lte',
                    'value' => 10000000,
                    'group_by' => 'branch_code',
                ],
                'severity' => 'Medium',
                'frequency' => 'Daily',
            ],
            [
                'name' => 'Toxic entitlement combinations in Finacle',
                'description' => 'Accounts holding both halves of a seeded toxic pair.',
                'rule_type' => 'sod_conflict',
                'control_id' => $accessControl?->id,
                'dataset_ids' => [$entitlements->id],
                'rule_definition' => [
                    'subject_field' => 'subject_identifier',
                    'function_field' => 'function',
                    'name_field' => 'subject_name',
                    'entity_field' => 'branch',
                    'system_key' => 'finacle',
                ],
                'severity' => 'Critical',
                'frequency' => 'Weekly',
            ],
            [
                'name' => 'Privileged accounts without MFA',
                'description' => 'Any admin-flagged directory account with MFA not registered.',
                'rule_type' => 'exception_list',
                'control_id' => $accessControl?->id,
                'dataset_ids' => [$directory->id],
                'rule_definition' => [
                    'conditions' => [
                        ['field' => 'isAdmin', 'operator' => 'eq', 'value' => '1'],
                        ['field' => 'isMfaRegistered', 'operator' => 'eq', 'value' => '0'],
                    ],
                    'match' => 'all',
                    'identifier_field' => 'userPrincipalName',
                ],
                'severity' => 'Critical',
                'frequency' => 'Daily',
            ],
            [
                'name' => 'AML alerts dispositioned within 72 hours',
                'description' => 'CBN expects an alert to be worked promptly; anything older than three days is an exception.',
                'rule_type' => 'timeliness',
                'control_id' => $glControl?->id,
                'dataset_ids' => [$alerts->id],
                'rule_definition' => [
                    'event_field' => 'raised_at',
                    'reference_field' => 'closed_at',
                    'max_hours' => 72,
                    'identifier_field' => 'alert_id',
                ],
                'severity' => 'High',
                'frequency' => 'Weekly',
            ],
        ];

        foreach ($rules as $definition) {
            $existing = MonitoringRule::withoutGlobalScopes()
                ->where('tenant_id', $tid)
                ->where('name', $definition['name'])
                ->first();

            if ($existing) {
                continue;
            }

            $rule = $monitoring->createRule([
                ...$definition,
                'tenant_id' => $tid,
                'owner_id' => $officer1->id,
                'auto_create_exception' => true,
            ], $officer1);

            $monitoring->submitRule($rule, $officer1);

            // Approved by the Control Function Head — never by the author.
            $monitoring->approveRule($rule->fresh(), $cfh);

            try {
                // No live extraction: the fixture snapshots above are what
                // the demo evaluates.
                $monitoring->runRule($rule->fresh(), 'manual', $officer1, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // ── A dead feed, because every bank has one ──────────────────
        $finacle->update([
            'consecutive_failures' => 3,
            'health_status' => 'Failed',
            'last_sync_status' => 'Failed',
            'last_sync_at' => now()->subHours(9),
            'paused_at' => now()->subHours(9),
            'paused_reason' => 'Paused after 3 consecutive failures. Last error: ORA-12170 TNS:Connect timeout occurred.',
        ]);

        Auth::logout();
    }

    /** @param array<string, mixed> $attributes */
    private function source(int $tenantId, array $attributes, User $approver): DataSource
    {
        $source = DataSource::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => $attributes['name']],
            [
                ...$attributes,
                'is_active' => true,
                'health_status' => 'Healthy',
                'last_sync_status' => 'Success',
                'last_sync_at' => now()->subHours(6),
                'credentials_ref' => 'data_source:'.$tenantId.':'.md5($attributes['name']),
                'approved_by' => $approver->id,
                'approved_at' => now()->subDays(20),
            ],
        );

        return $source;
    }

    /** @param array<string, mixed> $attributes */
    private function dataset(DataSource $source, array $attributes): DataSourceDataset
    {
        return DataSourceDataset::withoutGlobalScopes()->firstOrCreate(
            ['data_source_id' => $source->id, 'name' => $attributes['name']],
            [...$attributes, 'tenant_id' => $source->tenant_id, 'is_active' => true],
        );
    }

    /**
     * Write fixture rows straight into the snapshot store, applying the
     * dataset's own PII treatment on the way — so the seeded AML alerts
     * arrive with their BVNs already hashed, exactly as a live capture
     * would produce them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function snapshot(DataSourceDataset $dataset, array $rows): ?DataSnapshot
    {
        $existing = DataSnapshot::withoutGlobalScopes()
            ->where('dataset_id', $dataset->id)
            ->where('status', 'Ready')
            ->first();

        if ($existing) {
            return $existing;
        }

        $snapshots = app(SnapshotService::class);

        $snapshot = DataSnapshot::withoutGlobalScopes()->create([
            'tenant_id' => $dataset->tenant_id,
            'dataset_id' => $dataset->id,
            'snapshot_ref' => 'SNP-'.now()->year.'-'.str_pad((string) $dataset->id, 3, '0', STR_PAD_LEFT),
            'period_label' => now()->format('Y-m-d'),
            'status' => 'Capturing',
        ]);

        $path = $snapshots->pathFor($dataset, $snapshot);
        $body = '';

        foreach ($rows as $row) {
            $body .= json_encode($snapshots->redactRow($row, $dataset), JSON_UNESCAPED_UNICODE)."\n";
        }

        Storage::disk((string) config('monitoring.snapshot_disk'))->put($path, $body);

        $snapshot->update([
            'status' => 'Ready',
            'captured_at' => now()->subHours(6),
            'row_count' => count($rows),
            'size_bytes' => strlen($body),
            'checksum' => hash('sha256', $body),
            'storage_path' => $path,
            'expires_at' => now()->addDays($dataset->retention_days)->toDateString(),
        ]);

        $dataset->update(['row_count_last' => count($rows)]);

        return $snapshot->fresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function postingRows(): array
    {
        $rows = [];
        $branches = ['BR-001', 'BR-002', 'HO'];
        $makers = ['tolu.ade', 'ngozi.eze', 'ibrahim.sule', 'chinedu.obi'];

        for ($i = 1; $i <= 180; $i++) {
            $posted = now()->subDays($i % 21)->setTime(9 + ($i % 8), ($i * 7) % 60);
            $maker = $makers[$i % count($makers)];

            // Every ninth posting is self-approved, and every seventeenth is
            // over the branch limit — enough to make the demo findings real.
            $selfApproved = $i % 9 === 0;
            $large = $i % 17 === 0;

            $rows[] = [
                'transaction_id' => 'TXN'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'posted_at' => $posted->toDateTimeString(),
                'authorised_at' => $posted->copy()->addSeconds($selfApproved ? 20 : 3600)->toDateTimeString(),
                'amount' => $large ? 5000000 + ($i * 13571) : 25000 + ($i * 911),
                'currency' => 'NGN',
                'maker' => $maker,
                'checker' => $selfApproved ? $maker : $makers[($i + 1) % count($makers)],
                'branch_code' => $branches[$i % count($branches)],
                'channel' => $i % 3 === 0 ? 'BRANCH' : 'DIGITAL',
                'narration' => 'Seeded demo posting '.$i,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function entitlementRows(): array
    {
        $grants = [
            ['tolu.ade', 'Tolu Adeyemi', 'Marina Branch', ['TXN_ENTRY', 'TXN_AUTH']],
            ['ngozi.eze', 'Ngozi Ezeani', 'Ikeja Branch', ['TXN_ENTRY']],
            ['ibrahim.sule', 'Ibrahim Suleiman', 'Operations', ['VENDOR_CREATE', 'PAYMENT_RELEASE']],
            ['chinedu.obi', 'Chinedu Obiora', 'Operations', ['CUST_MAINT']],
            ['amaka.nwo', 'Amaka Nwosu', 'Head Office', ['CUST_MAINT', 'TXN_AUTH']],
            ['segun.bal', 'Segun Balogun', 'Treasury', ['TXN_ENTRY', 'RECON_VIEW']],
        ];

        $rows = [];

        foreach ($grants as [$id, $name, $branch, $functions]) {
            foreach ($functions as $function) {
                $rows[] = [
                    'subject_identifier' => $id,
                    'subject_name' => $name,
                    'branch' => $branch,
                    'function' => $function,
                    'granted_at' => now()->subMonths(6)->toDateString(),
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function directoryRows(): array
    {
        return [
            ['userPrincipalName' => 'adaeze.okafor@demobank.ng', 'displayName' => 'Adaeze Okafor', 'isAdmin' => '1', 'isMfaRegistered' => '1', 'accountEnabled' => '1'],
            ['userPrincipalName' => 'svc.batch@demobank.ng', 'displayName' => 'Batch service account', 'isAdmin' => '1', 'isMfaRegistered' => '0', 'accountEnabled' => '1'],
            ['userPrincipalName' => 'legacy.admin@demobank.ng', 'displayName' => 'Legacy administrator', 'isAdmin' => '1', 'isMfaRegistered' => '0', 'accountEnabled' => '1'],
            ['userPrincipalName' => 'babatunde.adewale@demobank.ng', 'displayName' => 'Babatunde Adewale', 'isAdmin' => '0', 'isMfaRegistered' => '1', 'accountEnabled' => '1'],
            ['userPrincipalName' => 'folake.balogun@demobank.ng', 'displayName' => 'Folake Balogun', 'isAdmin' => '0', 'isMfaRegistered' => '1', 'accountEnabled' => '1'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function alertRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= 40; $i++) {
            $raised = now()->subDays(30 - ($i % 28));
            // Every fifth alert sits well past the 72-hour window.
            $hours = $i % 5 === 0 ? 96 + $i : 12 + ($i % 40);

            $rows[] = [
                'alert_id' => 'AML'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'raised_at' => $raised->toDateTimeString(),
                'closed_at' => $raised->copy()->addHours($hours)->toDateTimeString(),
                'bvn' => '221'.str_pad((string) (1000000 + $i), 8, '0', STR_PAD_LEFT),
                'account_number' => '30'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                'scenario' => $i % 3 === 0 ? 'Structuring' : 'Unusual velocity',
                'disposition' => $i % 7 === 0 ? 'Escalated to SAR' : 'Closed — no further action',
            ];
        }

        return $rows;
    }
}
