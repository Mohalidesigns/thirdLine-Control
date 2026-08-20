<?php

namespace App\Dashboards\Sources;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\EffectivenessRating;
use App\Models\ObligationInstance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Predictive-lite (13.5) — DETERMINISTIC, not AI.
 *
 * Every score here is an arithmetic function of recorded history, and the
 * arithmetic is stated on the tile. That matters twice over: a second-line
 * officer can reproduce the number by hand, and nothing on this dashboard
 * is a model output that would need R4's human-approval gate. If these ever
 * become model-driven, they become drafts requiring approval, not tiles.
 */
class PredictiveSources extends SourceProvider
{
    public function sources(): array
    {
        return [
            $this->make(
                'controls_likely_to_fail', 'Controls most likely to fail next period', 'Forward look', 'view controls',
                ['table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->likelyToFail((int) ($config['limit'] ?? 8)),
                ['limit'],
            ),

            $this->make(
                'exception_recurrence_risk', 'Recurrence-prone controls', 'Forward look', 'view exceptions',
                ['table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->recurrence((int) ($config['limit'] ?? 8)),
                ['limit'],
            ),

            $this->make(
                'obligations_at_risk', 'Obligations at risk of being missed', 'Forward look', 'view obligations',
                ['table'],
                ['w' => 6, 'h' => 4],
                fn (User $user, array $config) => $this->obligationsAtRisk((int) ($config['days'] ?? 60)),
                ['days'],
            ),
        ];
    }

    /**
     * Score = 60 × (adverse ratings ÷ ratings in the last 24 months)
     *       + 40 × min(1, exceptions in the last 12 months ÷ 3).
     * Two grouped queries and one lookup — no per-control work.
     */
    private function likelyToFail(int $limit): array
    {
        $limit = min(20, max(3, $limit));

        $ratings = EffectivenessRating::query()
            ->where('status', 'Published')
            ->where('created_at', '>=', now()->subMonths(24))
            ->select('control_id', DB::raw('count(*) as rated'), DB::raw(
                "sum(case when overall_rating in ('Ineffective','Partially Effective') then 1 else 0 end) as adverse"
            ))
            ->groupBy('control_id')
            ->get()
            ->keyBy('control_id');

        $exceptions = ControlException::query()
            ->whereNotNull('control_id')
            ->where('date_raised', '>=', now()->subMonths(12))
            ->select('control_id', DB::raw('count(*) as total'))
            ->groupBy('control_id')
            ->pluck('total', 'control_id');

        $controlIds = $ratings->keys()->merge($exceptions->keys())->unique()->all();

        if ($controlIds === []) {
            return $this->rowsPayload([], 'No rating or exception history yet to project from.');
        }

        $controls = Control::query()
            ->whereIn('id', $controlIds)
            ->where('status', 'Active')
            ->get(['id', 'control_ref', 'title', 'is_key_control'])
            ->keyBy('id');

        $scored = [];

        foreach ($controls as $control) {
            $rating = $ratings->get($control->id);
            $rated = (int) ($rating->rated ?? 0);
            $adverse = (int) ($rating->adverse ?? 0);
            $exceptionCount = (int) ($exceptions[$control->id] ?? 0);

            $score = (int) round(
                ($rated > 0 ? 60 * ($adverse / $rated) : 0)
                + 40 * min(1, $exceptionCount / 3)
            );

            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'cells' => [
                    $control->control_ref,
                    $control->title,
                    $score.'%',
                    $rated > 0 ? "{$adverse}/{$rated} adverse · {$exceptionCount} exceptions" : "{$exceptionCount} exceptions",
                ],
                'sort' => $score,
                'tone' => match (true) {
                    $score >= 70 => 'critical',
                    $score >= 40 => 'serious',
                    default => 'warning',
                },
                'drill' => $this->drill('controls.show', ['control' => $control->id]),
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['sort'] <=> $a['sort']);

        return $this->rowsPayload(
            array_slice($scored, 0, $limit),
            'Score = 60% weight on adverse ratings over 24 months, 40% on exceptions raised over 12 months.',
            [
                ['key' => 'control', 'label' => 'Control'],
                ['key' => 'title', 'label' => 'Title'],
                ['key' => 'score', 'label' => 'Likelihood', 'align' => 'right'],
                ['key' => 'basis', 'label' => 'Basis'],
            ],
        );
    }

    /**
     * Recurrence probability = exceptions already flagged as a recurrence
     * ÷ exceptions raised on that control over 24 months.
     */
    private function recurrence(int $limit): array
    {
        $limit = min(20, max(3, $limit));

        $rows = ControlException::query()
            ->whereNotNull('control_id')
            ->where('date_raised', '>=', now()->subMonths(24))
            ->select('control_id', DB::raw('count(*) as total'), DB::raw(
                'sum(case when is_recurring = 1 then 1 else 0 end) as recurrences'
            ))
            ->groupBy('control_id')
            ->having('total', '>=', 2)
            ->get();

        if ($rows->isEmpty()) {
            return $this->rowsPayload([], 'No control has enough exception history to show a recurrence pattern.');
        }

        $controls = Control::query()
            ->whereIn('id', $rows->pluck('control_id'))
            ->get(['id', 'control_ref', 'title'])
            ->keyBy('id');

        $scored = $rows
            ->filter(fn ($row) => $controls->has($row->control_id) && $row->recurrences > 0)
            ->map(function ($row) use ($controls) {
                $probability = (int) round(($row->recurrences / $row->total) * 100);
                $control = $controls[$row->control_id];

                return [
                    'cells' => [
                        $control->control_ref,
                        $control->title,
                        $probability.'%',
                        "{$row->recurrences} of {$row->total} were repeats",
                    ],
                    'sort' => $probability,
                    'tone' => $probability >= 50 ? 'critical' : 'warning',
                    'drill' => $this->drill('exceptions.index', ['search' => $control->control_ref]),
                ];
            })
            ->sortByDesc('sort')
            ->take($limit)
            ->values()
            ->all();

        return $this->rowsPayload(
            $scored,
            'Probability = exceptions flagged as recurrences ÷ exceptions raised on the control over 24 months.',
            [
                ['key' => 'control', 'label' => 'Control'],
                ['key' => 'title', 'label' => 'Title'],
                ['key' => 'probability', 'label' => 'Recurrence', 'align' => 'right'],
                ['key' => 'basis', 'label' => 'Basis'],
            ],
        );
    }

    /**
     * An obligation is at risk when the clock has run further than the work
     * has: nothing started, or started with no evidence attached, inside the
     * window. Rank by days remaining — the nearest cliff first.
     */
    private function obligationsAtRisk(int $days): array
    {
        $days = min(365, max(7, $days));

        $instances = ObligationInstance::query()
            ->with(['obligation:id,title,obligation_ref'])
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays($days))
            ->where(fn ($q) => $q->where('status', 'Not Started')->orWhere('evidence_count', 0))
            ->orderBy('due_at')
            ->limit(15)
            ->get(['id', 'obligation_id', 'period_label', 'due_at', 'status', 'evidence_count']);

        $rows = $instances->map(function (ObligationInstance $instance) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($instance->due_at, false);

            return [
                'cells' => [
                    $instance->obligation?->title,
                    $instance->period_label,
                    $daysLeft < 0 ? abs($daysLeft).' days overdue' : $daysLeft.' days left',
                    $instance->status === 'Not Started' ? 'Not started' : 'No evidence attached',
                ],
                'tone' => match (true) {
                    $daysLeft < 0 => 'critical',
                    $daysLeft <= 14 => 'serious',
                    default => 'warning',
                },
                'drill' => $this->drill('obligations.instances.show', ['instance' => $instance->id]),
            ];
        })->all();

        return $this->rowsPayload(
            $rows,
            'Open instances falling due inside the window with no work recorded against them.',
            [
                ['key' => 'obligation', 'label' => 'Obligation'],
                ['key' => 'period', 'label' => 'Period'],
                ['key' => 'due', 'label' => 'Time left'],
                ['key' => 'reason', 'label' => 'Why it is flagged'],
            ],
        );
    }

    private function rowsPayload(array $rows, string $caption, array $columns = []): array
    {
        return [
            'kind' => 'rows',
            'columns' => $columns,
            'rows' => array_map(fn (array $row) => array_diff_key($row, ['sort' => null]), $rows),
            'total' => count($rows),
            'caption' => $caption,
            'deterministic' => true,
            'empty' => $rows === [],
        ];
    }
}
