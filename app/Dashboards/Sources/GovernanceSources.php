<?php

namespace App\Dashboards\Sources;

use App\Models\Complaint;
use App\Models\CsaCampaign;
use App\Models\CsaResponse;
use App\Models\ImprovementAction;
use App\Models\Incident;
use App\Models\Policy;
use App\Models\User;
use App\Support\Money;
use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;

/**
 * Policy, incident, complaint and campaign datasets (Phase 11 domains).
 */
class GovernanceSources extends SourceProvider
{
    public function sources(): array
    {
        return [
            $this->make(
                'incident_trend', 'Incident frequency and severity', 'Governance', 'view incidents',
                ['stacked_bar', 'line', 'table'],
                ['w' => 8, 'h' => 4],
                fn (User $user, array $config) => $this->incidentTrend((int) ($config['months'] ?? 12)),
                ['months'],
            ),

            $this->make(
                'incident_losses', 'Net operational losses', 'Governance', 'view incidents',
                ['stat', 'table'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->incidentLosses(),
            ),

            $this->make(
                'complaint_sla', 'Complaint SLA performance', 'Governance', 'view complaints',
                ['gauge', 'stat', 'table'],
                ['w' => 3, 'h' => 3],
                fn (User $user, array $config) => $this->complaintSla(),
            ),

            $this->make(
                'policy_status', 'Policies by status', 'Governance', 'view policies',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->policyStatus(),
            ),

            $this->make(
                'csa_response_rate', 'Campaign response rates', 'Governance', 'view campaigns',
                ['progress', 'horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->campaignResponse('csa'),
            ),

            $this->make(
                'attestation_completion', 'Attestation completion', 'Governance', 'view attestations',
                ['progress', 'horizontal_bar', 'table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->campaignResponse('attestation'),
            ),

            $this->make(
                'improvement_actions', 'Improvement actions', 'Governance', 'view improvements',
                ['stacked_bar', 'bar', 'table'],
                ['w' => 5, 'h' => 4],
                fn (User $user, array $config) => $this->improvements(),
            ),
        ];
    }

    private function incidentTrend(int $months): array
    {
        $months = min(24, max(3, $months));
        $buckets = $this->monthBuckets($months);
        $from = now()->startOfMonth()->subMonths($months - 1)->toDateString();

        $rows = Incident::query()
            ->selectRaw(SqlDialect::month('occurred_at').' as bucket, severity, count(*) as total')
            ->where('occurred_at', '>=', $from)
            ->groupBy('bucket', 'severity')
            ->get();

        $series = [];

        foreach (array_reverse(Incident::SEVERITIES) as $severity) {
            $series[] = [
                'key' => $severity,
                'label' => $severity,
                'tone' => self::SEVERITY_TONE[$severity] ?? 'muted',
                'values' => array_map(
                    fn (string $bucket) => (int) $rows->where('bucket', $bucket)->where('severity', $severity)->sum('total'),
                    array_keys($buckets),
                ),
            ];
        }

        return [
            'kind' => 'series',
            'categories' => array_map(fn (string $label, string $key) => [
                'key' => $key, 'label' => $label, 'drill' => null,
            ], array_values($buckets), array_keys($buckets)),
            'series' => $series,
            'total' => (int) $rows->sum('total'),
            'unit' => 'count',
            'stacked' => true,
            'drill' => $this->drill('incidents.index'),
            'empty' => $rows->isEmpty(),
        ];
    }

    /** Net loss per currency — never summed across currencies (R7). */
    private function incidentLosses(): array
    {
        $rows = Incident::query()
            ->whereNotNull('currency')
            ->where('near_miss', false)
            ->where('net_loss_minor', '>', 0)
            // net_loss_total, not net_loss: the model appends a net_loss
            // accessor that would shadow an aggregate of the same name.
            ->selectRaw('currency, count(*) as incident_count, sum(net_loss_minor) as net_loss_total')
            ->groupBy('currency')
            ->orderByDesc('net_loss_total')
            ->get()
            ->map(fn ($row) => Money::of((int) $row->net_loss_total, $row->currency)->jsonSerialize()
                + ['incidents' => (int) $row->incident_count])
            ->all();

        if ($rows === []) {
            return [
                'kind' => 'scalar',
                'value' => 0,
                'unit' => 'money',
                'currency' => 'NGN',
                'caption' => 'No net losses recorded',
                'empty' => true,
            ];
        }

        $primary = $rows[0];

        return [
            'kind' => 'scalar',
            'value' => $primary['amount'],
            'unit' => 'money',
            'currency' => $primary['currency'],
            'formatted' => $primary['formatted'],
            'caption' => $primary['incidents'].' incidents with a recorded net loss',
            'breakdown' => $rows,
            'drill' => $this->drill('incidents.index'),
        ];
    }

    private function complaintSla(): array
    {
        $totals = Complaint::query()
            ->selectRaw('count(*) as total, sum(case when sla_breached = 1 then 1 else 0 end) as breached')
            ->first();

        $total = (int) ($totals->total ?? 0);
        $breached = (int) ($totals->breached ?? 0);
        $withinSla = $total - $breached;

        return [
            'kind' => 'scalar',
            'value' => $this->percent($withinSla, $total),
            'unit' => 'percent',
            'tone' => $breached > 0 ? 'serious' : 'good',
            'caption' => "{$breached} of {$total} complaints breached their SLA",
            'drill' => $this->drill('complaints.index', ['breached_only' => 1]),
            'empty' => $total === 0,
        ];
    }

    private function policyStatus(): array
    {
        $counts = Policy::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categories = [];
        $values = [];

        foreach (Policy::STATUSES as $status) {
            $categories[] = [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('policies.index', ['status' => $status]),
            ];
            $values[] = (int) ($counts[$status] ?? 0);
        }

        $dueForReview = Policy::query()->dueForReview(30)->count();

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'status', 'label' => 'Policies', 'slot' => 1, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'caption' => "{$dueForReview} due for review within 30 days",
            'drill' => $this->drill('policies.index'),
            'empty' => array_sum($values) === 0,
        ];
    }

    /**
     * Response rate per open campaign. Counts come back grouped rather than
     * per campaign, so adding campaigns never adds queries.
     */
    private function campaignResponse(string $type): array
    {
        $campaigns = CsaCampaign::query()
            ->where('campaign_type', $type)
            ->whereIn('status', ['Open', 'Closed'])
            ->orderByDesc('opens_at')
            ->limit(8)
            ->get(['id', 'reference', 'name', 'status', 'closes_at']);

        if ($campaigns->isEmpty()) {
            return $this->emptyPayload();
        }

        $counts = CsaResponse::query()
            ->whereIn('campaign_id', $campaigns->pluck('id'))
            ->selectRaw("campaign_id,
                count(*) as total,
                sum(case when status in ('Submitted','Under Review','Accepted') then 1 else 0 end) as responded")
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $categories = [];
        $values = [];

        foreach ($campaigns as $campaign) {
            $row = $counts->get($campaign->id);
            $total = (int) ($row->total ?? 0);
            $responded = (int) ($row->responded ?? 0);

            $categories[] = [
                'key' => $campaign->reference,
                'label' => $campaign->name,
                'note' => "{$responded}/{$total}",
                'drill' => $this->drill('csa-campaigns.show', ['campaign' => $campaign->id]),
            ];
            $values[] = $this->percent($responded, $total);
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'response_rate', 'label' => 'Response rate', 'slot' => 1, 'values' => $values]],
            'unit' => 'percent',
            'max' => 100,
            'value' => count($values) > 0 ? round(array_sum($values) / count($values), 1) : 0,
            'drill' => $this->drill('csa-campaigns.index'),
        ];
    }

    private function improvements(): array
    {
        $rows = ImprovementAction::query()
            ->select('status', 'priority', DB::raw('count(*) as total'))
            ->groupBy('status', 'priority')
            ->get();

        $categories = array_map(fn (string $status) => [
            'key' => $status,
            'label' => $status,
            'drill' => $this->drill('improvements.index', ['status' => $status]),
        ], ImprovementAction::STATUSES);

        $series = [];

        foreach (array_reverse(ImprovementAction::PRIORITIES) as $priority) {
            $series[] = [
                'key' => $priority,
                'label' => $priority,
                'tone' => self::SEVERITY_TONE[$priority] ?? 'muted',
                'values' => array_map(
                    fn (string $status) => (int) $rows->where('status', $status)->where('priority', $priority)->sum('total'),
                    ImprovementAction::STATUSES,
                ),
            ];
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => $series,
            'total' => (int) $rows->sum('total'),
            'unit' => 'count',
            'stacked' => true,
            'drill' => $this->drill('improvements.index'),
            'empty' => $rows->isEmpty(),
        ];
    }
}
