<?php

namespace App\Dashboards\Sources;

use App\Models\ObligationInstance;
use App\Models\RegulatoryChange;
use App\Models\SubmissionPack;
use App\Models\User;
use App\Services\ObligationService;
use Illuminate\Support\Facades\DB;

/**
 * Regulatory datasets. Penalty exposure is money, so it travels as integer
 * minor units plus an ISO-4217 code all the way to the tile — a naira
 * exposure rendered as a dollar figure on a board screen is a defect (R7).
 */
class ComplianceSources extends SourceProvider
{
    public function __construct(private ObligationService $obligations) {}

    public function sources(): array
    {
        return [
            $this->make(
                'obligation_status', 'Obligation instances by status', 'Compliance', 'view obligations',
                ['stacked_bar', 'bar', 'donut', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->obligationStatus(),
            ),

            $this->make(
                'obligation_calendar', 'Regulatory calendar pressure', 'Compliance', 'view obligations',
                ['timeline', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->calendar((int) ($config['days'] ?? 90)),
                ['days'],
            ),

            $this->make(
                'penalty_exposure', 'Penalty exposure', 'Compliance', 'view obligations',
                ['stat', 'table'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->penaltyExposure($user),
            ),

            $this->make(
                'submission_status', 'Submission packs', 'Compliance', 'view submissions',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->submissions(),
            ),

            $this->make(
                'regulatory_change_pipeline', 'Regulatory change pipeline', 'Compliance', 'view regulatory-changes',
                ['bar', 'donut', 'table'],
                ['w' => 5, 'h' => 4],
                fn (User $user, array $config) => $this->changes(),
            ),
        ];
    }

    private function obligationStatus(): array
    {
        $counts = ObligationInstance::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = [];
        $values = [];
        $tones = [];

        foreach (ObligationInstance::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('obligations.instances.index', ['status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
            $tones[] = match ($status) {
                'Overdue', 'Rejected' => 'critical',
                'Not Started' => 'warning',
                'Accepted' => 'good',
                default => 'muted',
            };
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'status', 'label' => 'Instances', 'tones' => $tones, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('obligations.instances.index'),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function calendar(int $days): array
    {
        $days = min(365, max(7, $days));

        $instances = ObligationInstance::query()
            ->with(['obligation:id,title,obligation_ref,regulator_id', 'obligation.regulator:id,code'])
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays($days))
            ->orderBy('due_at')
            ->limit(15)
            ->get(['id', 'obligation_id', 'period_label', 'due_at', 'status']);

        return [
            'kind' => 'rows',
            'columns' => [
                ['key' => 'due', 'label' => 'Due'],
                ['key' => 'obligation', 'label' => 'Obligation'],
                ['key' => 'regulator', 'label' => 'Regulator'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $instances->map(function (ObligationInstance $instance) {
                $daysLeft = (int) now()->startOfDay()->diffInDays($instance->due_at, false);

                return [
                    'cells' => [
                        $instance->due_at?->toDateString(),
                        $instance->obligation?->title,
                        $instance->obligation?->regulator?->code ?? '—',
                        $instance->status,
                    ],
                    'tone' => match (true) {
                        $daysLeft < 0 => 'critical',
                        $daysLeft <= 7 => 'serious',
                        $daysLeft <= 30 => 'warning',
                        default => null,
                    },
                    'note' => $daysLeft < 0 ? abs($daysLeft).' days overdue' : $daysLeft.' days left',
                    'drill' => $this->drill('obligations.instances.show', ['instance' => $instance->id]),
                ];
            })->all(),
            'total' => $instances->count(),
            'drill' => $this->drill('obligations.calendar'),
            'empty' => $instances->isEmpty(),
        ];
    }

    /**
     * Exposure is reported per currency and never summed across them. A
     * "total exposure" that silently added naira to dollars would be the
     * kind of number a board acts on and a regulator disputes.
     */
    private function penaltyExposure(User $user): array
    {
        $byCurrency = $this->obligations->exposureByCurrency($user->tenant_id);

        if ($byCurrency === []) {
            return [
                'kind' => 'scalar',
                'value' => 0,
                'unit' => 'money',
                'currency' => 'NGN',
                'caption' => 'No penalty exposure on open obligations',
                'empty' => true,
            ];
        }

        uasort($byCurrency, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);
        $primary = $byCurrency[array_key_first($byCurrency)];
        $others = array_slice($byCurrency, 1);

        return [
            'kind' => 'scalar',
            'value' => $primary['amount'],
            'unit' => 'money',
            'currency' => $primary['currency'],
            'formatted' => $primary['formatted'],
            'tone' => 'serious',
            'caption' => $others !== []
                ? 'Plus '.implode(', ', array_column($others, 'formatted'))
                : 'Across open and overdue obligation instances',
            'breakdown' => array_values($byCurrency),
            'drill' => $this->drill('obligations.instances.index', ['status' => 'Overdue']),
        ];
    }

    private function submissions(): array
    {
        $counts = SubmissionPack::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = [];
        $values = [];
        $tones = [];

        foreach (SubmissionPack::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('submissions.index', ['status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
            $tones[] = match ($status) {
                'Rejected' => 'critical',
                'Draft' => 'warning',
                'Acknowledged' => 'good',
                default => 'muted',
            };
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'status', 'label' => 'Packs', 'tones' => $tones, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('submissions.index'),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function changes(): array
    {
        $counts = RegulatoryChange::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = [];
        $values = [];

        foreach (RegulatoryChange::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('regulatory-changes.index', ['status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
        }

        $unassessed = (int) ($counts['New'] ?? 0);

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'status', 'label' => 'Publications', 'slot' => 1, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'caption' => "{$unassessed} awaiting impact assessment",
            'drill' => $this->drill('regulatory-changes.index'),
            'empty' => array_sum($values) === 0,
        ];
    }
}
