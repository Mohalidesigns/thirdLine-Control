<?php

namespace App\Http\Requests;

use App\Models\MaterialityAssessment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialityAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage sustainability');
    }

    public function rules(): array
    {
        return [
            'topic_id' => ['required', 'exists:sustainability_topics,id'],
            'entity_id' => ['nullable', 'exists:entities,id'],
            'period_label' => ['required', 'string', 'max:40'],
            'basis' => ['required', Rule::in(MaterialityAssessment::BASES)],
            'financial_materiality_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'impact_materiality_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_material' => ['required', 'boolean'],
            'rationale' => ['required', 'string', 'max:4000'],
            'stakeholders_consulted' => ['nullable', 'array', 'max:50'],
            'stakeholders_consulted.*' => ['string', 'max:255'],
        ];
    }

    /**
     * A double-materiality election has to actually assess both dimensions.
     * Recording 'double' with only the financial score filled in would
     * misrepresent the basis on which a disclosure decision was taken.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('basis') !== 'double') {
                    return;
                }

                if ($this->input('impact_materiality_score') === null) {
                    $validator->errors()->add(
                        'impact_materiality_score',
                        'A double-materiality assessment must score impact materiality as well as financial materiality.'
                    );
                }
            },
        ];
    }
}
