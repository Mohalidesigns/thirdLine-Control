<?php

namespace App\Http\Requests;

use App\Models\GhgDataPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GhgDataPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('prepare ghg-data');
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'exists:entities,id'],
            'scope' => ['required', Rule::in(GhgDataPoint::SCOPES)],
            'category' => ['required', 'string', 'max:255'],
            'period_label' => ['required', 'string', 'max:40'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'activity_data' => ['nullable', 'numeric'],
            'activity_unit' => ['nullable', 'string', 'max:40'],
            'emission_factor' => ['nullable', 'numeric'],
            'emission_factor_source' => ['nullable', 'string', 'max:255'],
            'data_quality' => ['required', Rule::in(GhgDataPoint::DATA_QUALITIES)],
            'methodology' => ['required', Rule::in(GhgDataPoint::METHODOLOGIES)],
            // The control over the figure and the evidence behind it are
            // what make it assurable. Not required at draft — a figure can
            // be captured before its control exists — but their absence is
            // reported as a lineage gap on every screen that shows it.
            'control_id' => ['nullable', 'exists:controls,id'],
            'evidence_id' => ['nullable', 'exists:evidence,id'],
        ];
    }
}
