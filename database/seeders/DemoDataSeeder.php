<?php

namespace Database\Seeders;

use App\Models\BusinessProcess;
use App\Models\CheckResult;
use App\Models\Control;
use App\Models\ControlCategory;
use App\Models\ControlException;
use App\Models\EffectivenessRating;
use App\Models\OrganisationUnit;
use App\Models\RatingMatrixEntry;
use App\Models\Risk;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrFail();
        $tid = $tenant->id;

        $cfh = User::where('email', 'cfh@secondline.test')->first();
        $officer1 = User::where('email', 'officer1@secondline.test')->first();
        $officer2 = User::where('email', 'officer2@secondline.test')->first();
        $owner1 = User::where('email', 'owner1@secondline.test')->first();
        $owner2 = User::where('email', 'owner2@secondline.test')->first();

        $ops = OrganisationUnit::withoutGlobalScopes()->where('name', 'Operations')->first();
        $marina = OrganisationUnit::withoutGlobalScopes()->where('name', 'Marina Branch')->first();
        $it = OrganisationUnit::withoutGlobalScopes()->where('name', 'Information Technology')->first();

        $cashProcess = BusinessProcess::withoutGlobalScopes()->where('code', 'CASH-MGT')->first();
        $payProcess = BusinessProcess::withoutGlobalScopes()->where('code', 'PAY-PROC')->first();
        $reconProcess = BusinessProcess::withoutGlobalScopes()->where('code', 'RECON')->first();
        $itProcess = BusinessProcess::withoutGlobalScopes()->where('code', 'IT-CHG')->first();

        $cat = fn (string $name) => ControlCategory::where('name', $name)->first();

        // ── Live controls in various lifecycle states ────────────────
        $controls = [
            ['CTL-001', 'Maker-checker on outward transfers', 'Segregation of Duties', 'Preventive', 'IT-Dependent Manual', 'Monthly', 'Active', $payProcess, $ops, $owner1],
            ['CTL-002', 'Daily teller till proof', 'Cash Handling', 'Detective', 'Manual', 'Daily', 'Active', $cashProcess, $marina, $owner2],
            ['CTL-003', 'Suspense account reconciliation', 'Reconciliation', 'Detective', 'Manual', 'Weekly', 'Active', $reconProcess, $ops, $owner1],
            ['CTL-004', 'Quarterly access recertification', 'Access Management', 'Detective', 'Manual', 'Quarterly', 'Active', $itProcess, $it, $owner1],
            ['CTL-005', 'Vault dual custody', 'Dual Control', 'Preventive', 'Manual', 'Monthly', 'Active', $cashProcess, $marina, $owner2],
            ['CTL-006', 'Production change approval', 'Change Management', 'Preventive', 'Manual', 'Monthly', 'Pending Approval', $itProcess, $it, $owner1],
            ['CTL-007', 'Dormant account reactivation authorisation', 'Authorisation', 'Preventive', 'Manual', 'Monthly', 'Draft', $payProcess, $ops, $owner1],
        ];

        $created = [];
        foreach ($controls as [$ref, $title, $category, $type, $nature, $frequency, $status, $process, $unit, $owner]) {
            $control = Control::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tid, 'control_ref' => $ref],
                [
                    'title' => $title,
                    'description' => "Demo control: {$title}.",
                    'objective' => "Ensure the objective behind '{$title}' is met consistently.",
                    'type' => $type,
                    'nature' => $nature,
                    'category_id' => $cat($category)?->id,
                    'process_id' => $process?->id,
                    'unit_id' => $unit?->id,
                    'owner_id' => $owner?->id,
                    'frequency' => $frequency,
                    'is_key_control' => true,
                    'status' => $status,
                    'created_by' => $officer1->id,
                    'approved_by' => $status === 'Active' ? $cfh->id : null,
                    'approved_at' => $status === 'Active' ? now()->subMonths(3) : null,
                ],
            );

            if (! $control->versions()->exists()) {
                $control->versions()->create([
                    'version_no' => 1,
                    'payload' => $control->only(['title', 'description', 'objective', 'type', 'nature', 'frequency', 'status']),
                    'effective_from' => now()->subMonths(3)->toDateString(),
                    'changed_by' => $officer1->id,
                    'change_reason' => 'Initial definition',
                ]);
            }

            $created[$ref] = $control;
        }

        // ── Risk register with mappings ──────────────────────────────
        $risks = [
            ['RSK-001', 'Unauthorised payment release', 'Operational', 4, 5, ['CTL-001']],
            ['RSK-002', 'Teller cash misappropriation', 'Operational', 3, 4, ['CTL-002', 'CTL-005']],
            ['RSK-003', 'Unreconciled suspense build-up', 'Financial Reporting', 3, 3, ['CTL-003']],
            ['RSK-004', 'Excessive or stale system access', 'Information Security', 4, 4, ['CTL-004']],
            ['RSK-005', 'Unauthorised production change', 'Technology', 3, 5, []],           // control gap
        ];

        foreach ($risks as [$code, $title, $category, $likelihood, $impact, $mappedRefs]) {
            $risk = Risk::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tid, 'code' => $code],
                [
                    'title' => $title,
                    'description' => "Demo risk: {$title}.",
                    'category' => $category,
                    'inherent_likelihood' => $likelihood,
                    'inherent_impact' => $impact,
                    'inherent_rating' => $likelihood * $impact,
                    'residual_rating' => $likelihood * $impact,
                    'risk_owner_id' => $owner1->id,
                    'status' => 'active',
                ],
            );

            foreach ($mappedRefs as $ref) {
                $risk->controls()->syncWithoutDetaching([
                    $created[$ref]->id => ['contribution_weight' => 1, 'mapped_by' => $officer1->id, 'mapped_at' => now()],
                ]);
            }
        }

        // ── Test scripts with check items ────────────────────────────
        $scripts = [
            ['CTL-001', 'Outward transfer maker-checker test', [
                ['Select a sample of outward transfers and confirm each has distinct maker and checker IDs.', 'High'],
                ['Confirm no user holds both initiation and approval rights in the payment system.', 'Critical'],
                ['Verify approval limits matched officer grade for each sampled transaction.', 'Medium'],
            ]],
            ['CTL-002', 'Teller till proof test', [
                ['Confirm each teller till was proved to the system position at close of business.', 'High'],
                ['Verify overages/shortages were recorded and dispositioned same day.', 'Medium'],
                ['Confirm till limits were not exceeded intraday.', 'Low'],
            ]],
            ['CTL-003', 'Suspense reconciliation test', [
                ['Confirm weekly reconciliation was prepared and independently reviewed.', 'High'],
                ['Verify open items above 30 days are escalated with an action plan.', 'Medium'],
            ]],
            ['CTL-004', 'Access recertification test', [
                ['Confirm the quarterly recertification covered all in-scope applications.', 'High'],
                ['Verify revocations identified during recertification were actioned within 5 days.', 'Critical'],
            ]],
        ];

        foreach ($scripts as [$ref, $title, $items]) {
            $script = TestScript::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tid, 'control_id' => $created[$ref]->id, 'version_no' => 1],
                [
                    'title' => $title,
                    'objective' => 'Confirm the control operated as designed during the period.',
                    'status' => 'Active',
                    'created_by' => $officer1->id,
                    'approved_by' => $cfh->id,
                    'approved_at' => now()->subMonths(2),
                ],
            );

            if (! $script->checkItems()->exists()) {
                foreach ($items as $i => [$question, $severity]) {
                    $script->checkItems()->create([
                        'sequence' => $i + 1,
                        'question' => $question,
                        'is_mandatory' => true,
                        'default_severity_on_fail' => $severity,
                    ]);
                }
            }
        }

        // ── Test instances across states ─────────────────────────────
        $this->seedTestInstance($created['CTL-001'], 'Jun-2026', now()->subMonths(2), 'Reviewed', $officer1, $cfh, true);
        $this->seedTestInstance($created['CTL-002'], 'Jun-2026', now()->subMonths(2), 'Reviewed', $officer2, $cfh, false);
        $this->seedTestInstance($created['CTL-001'], 'Jul-2026', now()->subMonth(), 'Submitted', $officer1, null, true);
        $this->seedTestInstance($created['CTL-003'], 'W30-2026', now()->subWeeks(2), 'In Progress', $officer2, null, false);
        $this->seedTestInstance($created['CTL-004'], 'Q2-2026', now()->subMonths(2), 'Scheduled', null, null, false, overdueDays: 20);
        $this->seedTestInstance($created['CTL-005'], 'Jul-2026', now()->subMonth(), 'Scheduled', $officer2, null, false, overdueDays: 5);

        // ── Exceptions across the lifecycle ──────────────────────────
        $exceptions = [
            ['EXC-2026-001', 'Payment approved by initiating officer', 'Critical', 'In Progress', 'CTL-001', $owner1, 45, -10, false],
            ['EXC-2026-002', 'Till shortage not dispositioned same day', 'Medium', 'Assigned', 'CTL-002', $owner2, 20, 10, false],
            ['EXC-2026-003', 'Suspense items above 30 days without escalation', 'High', 'Remediated', 'CTL-003', $owner1, 35, 5, false],
            ['EXC-2026-004', 'Stale accounts not revoked after recertification', 'Critical', 'Open', 'CTL-004', null, 8, -3, false],
            ['EXC-2026-005', 'Vault register missing second custodian signature', 'Low', 'Verified-Closed', 'CTL-005', $owner2, 60, 30, true],
            ['EXC-2026-006', 'Repeat: payment approved by initiating officer', 'High', 'Assigned', 'CTL-001', $owner1, 12, 18, false],
        ];

        $previous = null;
        foreach ($exceptions as [$ref, $title, $severity, $status, $controlRef, $owner, $ageDays, $daysToTarget, $closed]) {
            $control = $created[$controlRef];
            $raised = now()->subDays($ageDays);
            $target = now()->addDays($daysToTarget);

            $exception = ControlException::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tid, 'reference' => $ref],
                [
                    'source_type' => 'Manual',
                    'control_id' => $control->id,
                    'risk_id' => $control->risks()->withoutGlobalScopes()->first()?->id,
                    'title' => $title,
                    'description' => "Demo exception: {$title}.",
                    'root_cause' => 'Process discipline gap identified during control testing.',
                    'severity' => $severity,
                    'owner_id' => $owner?->id,
                    'unit_id' => $control->unit_id,
                    'raised_by' => $officer1->id,
                    'date_raised' => $raised->toDateString(),
                    'target_closure_date' => $target->toDateString(),
                    'status' => $status,
                    'age_days' => $ageDays,
                    'is_overdue' => $daysToTarget < 0 && ! $closed,
                    'is_recurring' => str_starts_with($title, 'Repeat'),
                    'recurrence_of_exception_id' => str_starts_with($title, 'Repeat') ? $previous?->id : null,
                    'remediated_at' => in_array($status, ['Remediated', 'Verified-Closed']) ? now()->subDays(3) : null,
                    'remediated_by' => in_array($status, ['Remediated', 'Verified-Closed']) ? $owner?->id : null,
                    'verification_method' => $closed ? 'Re-performance of the control for the subsequent period' : null,
                    'verified_by' => $closed ? $cfh->id : null,
                    'verified_at' => $closed ? now()->subDay() : null,
                ],
            );

            if ($exception->wasRecentlyCreated) {
                $exception->logActivity('Status Change', null, $status, 'Seeded demo state.');
            }

            if ($ref === 'EXC-2026-001') {
                $previous = $exception;
            }
        }
    }

    private function seedTestInstance(
        Control $control,
        string $periodLabel,
        Carbon $periodStart,
        string $status,
        ?User $tester,
        ?User $reviewer,
        bool $withFailure,
        int $overdueDays = 0,
    ): void {
        $existing = TestInstance::withoutGlobalScopes()
            ->where('control_id', $control->id)
            ->where('period_label', $periodLabel)
            ->first();

        if ($existing) {
            return;
        }

        $script = TestScript::withoutGlobalScopes()->where('control_id', $control->id)->first();

        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $control->tenant_id,
            'control_id' => $control->id,
            'test_script_id' => $script?->id,
            'reference' => 'TST-2026-'.str_pad((string) (TestInstance::withoutGlobalScopes()->count() + 1), 3, '0', STR_PAD_LEFT),
            'period_label' => $periodLabel,
            'period_start' => $periodStart->copy()->startOfMonth(),
            'period_end' => $periodStart->copy()->endOfMonth(),
            'due_date' => $overdueDays > 0 ? now()->subDays($overdueDays) : $periodStart->copy()->endOfMonth()->addDays(5),
            'assigned_tester_id' => $tester?->id,
            'reviewer_id' => $reviewer?->id,
            'status' => $status,
            'population_size' => 250,
            'sample_size' => 25,
            'sampling_method' => 'Random sampling',
            'started_at' => in_array($status, ['In Progress', 'Submitted', 'Reviewed']) ? $periodStart->copy()->addDays(2) : null,
            'submitted_at' => in_array($status, ['Submitted', 'Reviewed']) ? $periodStart->copy()->addDays(4) : null,
            'reviewed_at' => $status === 'Reviewed' ? $periodStart->copy()->addDays(6) : null,
        ]);

        if ($script && in_array($status, ['Submitted', 'Reviewed'], true)) {
            foreach ($script->checkItems as $i => $item) {
                CheckResult::create([
                    'test_instance_id' => $instance->id,
                    'check_item_id' => $item->id,
                    'result' => ($withFailure && $i === 0) ? 'Fail' : 'Pass',
                    'comment' => ($withFailure && $i === 0) ? 'Sampled item failed the check — see linked exception.' : null,
                    'tested_by' => $tester?->id,
                    'tested_at' => $periodStart->copy()->addDays(3),
                ]);
            }
        }

        if ($status === 'Reviewed' && $tester) {
            $design = 'Effective';
            $operating = $withFailure ? 'Partially Effective' : 'Effective';

            EffectivenessRating::withoutGlobalScopes()->create([
                'tenant_id' => $control->tenant_id,
                'test_instance_id' => $instance->id,
                'control_id' => $control->id,
                'period_label' => $periodLabel,
                'design_effectiveness' => $design,
                'operating_effectiveness' => $operating,
                'operating_rationale' => $withFailure ? 'One sampled item failed during the period.' : null,
                'overall_rating' => RatingMatrixEntry::resolve($design, $operating, $control->tenant_id),
                'status' => 'Published',
                'rated_by' => $tester->id,
                'approved_by' => $control->approved_by,
                'approved_at' => $periodStart->copy()->addDays(7),
            ]);
        }
    }
}
