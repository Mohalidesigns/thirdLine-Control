<?php

namespace Database\Seeders;

use App\Models\ReportDefinition;
use App\Models\ReportSchedule;
use App\Models\SubmissionPack;
use App\Models\User;
use App\Services\SubmissionPackService;
use App\Support\CronSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Phase 13 demo data: a scheduled board pack, and two submission packs that
 * between them show both halves of the story.
 *
 * The FRC/CG/001 pack is deliberately left blocked. Every Nigerian content
 * pack ships unverified (R10), so a corporate governance return generated
 * today excludes all 28 principles and refuses to be filed until somebody has
 * checked the Code's text against the FRC's own document. That refusal IS the
 * differentiator, and a demo that faked verification to show a green tick
 * would be demonstrating the opposite of what the product does.
 *
 * The NDIC pack is taken all the way through generate → review → approve →
 * file, by three different people, to show maker-checker working end to end.
 */
class Phase13DemoSeeder extends Seeder
{
    public function run(): void
    {
        $cfh = User::where('email', 'cfh@secondline.test')->first();
        $officer1 = User::where('email', 'officer1@secondline.test')->first();
        $officer2 = User::where('email', 'officer2@secondline.test')->first();

        if (! $cfh || ! $officer1 || ! $officer2) {
            return;
        }

        $this->scheduleBoardPack($cfh);
        $this->governanceReturn($officer1);
        $this->dpasReturn($officer1, $officer2, $cfh);

        Auth::logout();
    }

    private function scheduleBoardPack(User $cfh): void
    {
        Auth::login($cfh);

        $definition = ReportDefinition::where('code', 'BOARD-PACK')->first();

        if (! $definition) {
            return;
        }

        ReportSchedule::firstOrCreate(
            ['tenant_id' => $cfh->tenant_id, 'report_definition_id' => $definition->id, 'name' => 'Quarterly board pack'],
            [
                'cron' => '0 7 1 1,4,7,10 *',
                'timezone' => 'Africa/Lagos',
                'parameters' => ['period' => 'last_quarter'],
                'output_format' => 'pdf',
                'recipients' => ['role_names' => ['Executive Viewer', 'Control Function Head']],
                'delivery_method' => 'in_app',
                'is_active' => true,
                'created_by' => $cfh->id,
                'next_run_at' => CronSchedule::nextRun('0 7 1 1,4,7,10 *', 'Africa/Lagos'),
            ],
        );
    }

    /** The blocked pack: unverified principles, excluded and listed. */
    private function governanceReturn(User $officer): void
    {
        Auth::login($officer);

        if (SubmissionPack::where('pack_type', 'FRC/CG/001')->exists()) {
            return;
        }

        try {
            app(SubmissionPackService::class)->generate('FRC/CG/001', $officer, [
                'period_label' => 'FY '.(now()->year - 1),
            ]);
        } catch (Throwable $e) {
            $this->command?->warn('FRC/CG/001 demo pack could not be generated: '.$e->getMessage());
        }
    }

    /** The complete pack: generated, reviewed, approved and filed — three people. */
    private function dpasReturn(User $generator, User $reviewer, User $approver): void
    {
        if (SubmissionPack::where('pack_type', 'NDIC/DPAS')->exists()) {
            return;
        }

        $service = app(SubmissionPackService::class);

        try {
            Auth::login($generator);

            $pack = $service->generate('NDIC/DPAS', $generator, [
                'period_label' => 'FY '.(now()->year - 1),
            ]);

            $service->update($pack, $generator, $this->dpasAnswers());
            $service->sendForReview($pack, $generator);

            Auth::login($reviewer);
            $service->review($pack->fresh(), $reviewer, 'accept', 'Figures agreed to the control register.');

            Auth::login($approver);
            $pack = $pack->fresh();
            $service->approve($pack, $approver);
            $service->file($pack->fresh(), $approver, [
                'submission_reference' => 'NDIC/DPAS/'.now()->year.'/0187',
            ]);
        } catch (Throwable $e) {
            $this->command?->warn('NDIC/DPAS demo pack stopped at: '.$e->getMessage());
        }
    }

    /**
     * Every required free-text answer on the DPAS pack, so the demo lands
     * above the completeness floor rather than at 40% with a refusal.
     *
     * @return array<string, mixed>
     */
    private function dpasAnswers(): array
    {
        return [
            // 250,000,000.00 NGN in minor units — R7: integers, never floats.
            'deposit_base_minor' => 25_000_000_000,
            'returns_narrative' => 'Two monthly returns were filed one working day late in the period following '
                .'a core banking upgrade. The upgrade window has been moved outside the reporting cut-off.',
            'examiner_narrative' => 'Two examiner recommendations remain open, both on branch cash handling. '
                .'Both are inside their agreed remediation dates.',
            'control_narrative' => 'Key controls over the financial reporting cycle were tested in the period. '
                .'The residual weaknesses are concentrated in branch operations and are tracked as exceptions.',
            'risk_framework_reviewed_on' => now()->subMonths(4)->toDateString(),
            'risk_narrative' => 'The enterprise risk management framework was reviewed by the board risk committee '
                .'in the period. Risk appetite statements are approved and in force.',
            'premium_narrative' => 'The management factors carry a modest add-on this cycle. Closing the two open '
                .'examiner recommendations before the next assessment removes the largest single component.',
        ];
    }
}
