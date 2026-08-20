<?php

namespace App\Dashboards\Sources;

use App\Models\Control;
use App\Models\MonitoringFinding;
use App\Models\MonitoringRule;
use App\Models\SodViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Continuous monitoring and segregation-of-duties datasets (Phase 12).
 */
class MonitoringSources extends SourceProvider
{
    public function sources(): array
    {
        return [
            $this->make(
                'monitoring_coverage', 'Continuous monitoring coverage', 'Monitoring', 'view monitoring',
                ['stat', 'progress'],
                ['w' => 3, 'h' => 2],
                fn (User $user, array $config) => $this->coverage(),
            ),

            $this->make(
                'monitoring_findings', 'Open monitoring findings', 'Monitoring', 'view monitoring',
                ['donut', 'bar', 'table'],
                ['w' => 4, 'h' => 4],
                fn (User $user, array $config) => $this->findings(),
            ),

            $this->make(
                'sod_exposure', 'Segregation-of-duties exposure', 'Monitoring', 'view sod',
                ['stat', 'bar', 'table'],
                ['w' => 4, 'h' => 3],
                fn (User $user, array $config) => $this->sod(),
            ),
        ];
    }

    private function coverage(): array
    {
        $keyControls = Control::query()
            ->where('is_template', false)
            ->where('status', 'Active')
            ->where('is_key_control', true)
            ->count();

        $monitored = MonitoringRule::query()
            ->where('status', 'Active')
            ->whereNotNull('control_id')
            ->distinct()
            ->count('control_id');

        return [
            'kind' => 'scalar',
            'value' => $this->percent($monitored, $keyControls),
            'unit' => 'percent',
            'caption' => "{$monitored} of {$keyControls} key controls have an active monitoring rule",
            'drill' => $this->drill('monitoring-rules.index', ['status' => 'Active']),
            'empty' => $keyControls === 0,
        ];
    }

    private function findings(): array
    {
        $counts = MonitoringFinding::query()
            ->whereIn('status', MonitoringFinding::OPEN_STATUSES)
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $categories = [];
        $values = [];
        $tones = [];

        foreach (array_keys(self::SEVERITY_TONE) as $severity) {
            $categories[] = [
                'key' => $severity,
                'label' => $severity,
                'drill' => $this->drill('monitoring-findings.index', ['severity' => $severity]),
            ];
            $values[] = (int) ($counts[$severity] ?? 0);
            $tones[] = self::SEVERITY_TONE[$severity];
        }

        return [
            'kind' => 'series',
            'categories' => $categories,
            'series' => [['key' => 'severity', 'label' => 'Open findings', 'tones' => $tones, 'values' => $values]],
            'total' => array_sum($values),
            'unit' => 'count',
            'drill' => $this->drill('monitoring-findings.index'),
            'empty' => array_sum($values) === 0,
        ];
    }

    private function sod(): array
    {
        $counts = SodViolation::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $open = (int) ($counts['Open'] ?? 0);
        $accepted = (int) ($counts['Accepted'] ?? 0);

        return [
            'kind' => 'series',
            'value' => $open,
            'categories' => array_map(fn (string $status) => [
                'key' => $status,
                'label' => $status,
                'drill' => $this->drill('sod.violations', ['status' => $status]),
            ], SodViolation::STATUSES),
            'series' => [[
                'key' => 'violations',
                'label' => 'Violations',
                'slot' => 1,
                'values' => array_map(fn (string $status) => (int) ($counts[$status] ?? 0), SodViolation::STATUSES),
            ]],
            'total' => (int) array_sum($counts->all()),
            'unit' => 'count',
            'tone' => $open > 0 ? 'critical' : 'good',
            'caption' => "{$open} unmitigated, {$accepted} formally accepted",
            'drill' => $this->drill('sod.violations'),
            'empty' => $counts->isEmpty(),
        ];
    }
}
