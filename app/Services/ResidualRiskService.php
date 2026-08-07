<?php

namespace App\Services;

use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\Risk;

class ResidualRiskService
{
    /**
     * Effectiveness → reduction factor. An Effective control halves the
     * weighted exposure it covers; Ineffective/Not Tested reduce nothing,
     * so a risk with no Effective control never falls below inherent (§7.3).
     */
    private const REDUCTION = [
        'Effective' => 0.5,
        'Partially Effective' => 0.25,
        'Ineffective' => 0.0,
        'Not Tested' => 0.0,
    ];

    public function recomputeForControl(Control $control): void
    {
        $control->risks()->withoutGlobalScopes()->get()
            ->each(fn (Risk $risk) => $this->recompute($risk));
    }

    /**
     * Residual = inherent × (1 − weighted mean reduction across mapped
     * active controls and approved active compensating controls), floored
     * at 20% of inherent (FR-2.4).
     */
    public function recompute(Risk $risk): void
    {
        $controls = $risk->controls()
            ->withoutGlobalScopes()
            ->where('status', 'Active')
            ->get();

        if ($controls->isEmpty()) {
            $risk->updateQuietly(['residual_rating' => $risk->inherent_rating]);

            return;
        }

        $totalWeight = 0.0;
        $weightedReduction = 0.0;

        foreach ($controls as $control) {
            $weight = (float) ($control->pivot->contribution_weight ?? 1);
            $rating = $control->latestPublishedRating()?->overall_rating ?? 'Not Tested';
            $reduction = self::REDUCTION[$rating] ?? 0.0;

            $compensation = $control->exceptions()
                ->withoutGlobalScopes()
                ->whereIn('status', ['Open', 'Assigned', 'In Progress', 'Remediated'])
                ->exists()
                ? $this->activeCompensationBonus($control)
                : 0.0;

            $totalWeight += $weight;
            $weightedReduction += $weight * min(0.5, $reduction + $compensation);
        }

        $meanReduction = $totalWeight > 0 ? $weightedReduction / $totalWeight : 0.0;
        $residual = max($risk->inherent_rating * (1 - $meanReduction), $risk->inherent_rating * 0.2);

        $risk->updateQuietly(['residual_rating' => round($residual, 2)]);
    }

    /** Approved, in-date compensating controls contribute a partial reduction (FR-6.3). */
    private function activeCompensationBonus(Control $control): float
    {
        $active = CompensatingControl::withoutGlobalScopes()
            ->where('primary_control_id', $control->id)
            ->whereIn('status', ['Approved', 'Active'])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
            ->exists();

        return $active ? 0.15 : 0.0;
    }
}
