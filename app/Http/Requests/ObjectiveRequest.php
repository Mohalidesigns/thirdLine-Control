<?php

namespace App\Http\Requests;

use App\Models\Objective;
use App\Rules\RichTextRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObjectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage objectives');
    }

    public function rules(): array
    {
        $objectiveId = $this->route('objective')?->id;

        return [
            'entity_id' => ['nullable', 'exists:entities,id'],
            'parent_objective_id' => ['nullable', 'exists:objectives,id'],
            'perspective' => ['required', Rule::in(Objective::PERSPECTIVES)],
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('objectives', 'code')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($objectiveId)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'owner_id' => ['nullable', 'tenant_user'],
            'period' => ['nullable', 'string', 'max:40'],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'progress_mode' => ['required', Rule::in(Objective::PROGRESS_MODES)],
            'weight' => ['required', 'integer', 'min:1', 'max:1000'],

            'measures' => ['nullable', 'array', 'max:20'],
            'measures.*.metric_id' => ['required', 'exists:metrics,id'],
            'measures.*.baseline_value' => ['nullable', 'numeric'],
            'measures.*.target_value' => ['nullable', 'numeric'],
            'measures.*.weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'measures.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * An objective cannot be its own ancestor. A cycle would make the
     * roll-up non-terminating and the strategy map meaningless, so it is
     * refused at the door rather than defended against at read time.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $parentId = $this->input('parent_objective_id');
                $objectiveId = $this->route('objective')?->id;

                if (! $parentId || ! $objectiveId) {
                    return;
                }

                if ((int) $parentId === (int) $objectiveId) {
                    $validator->errors()->add('parent_objective_id', 'An objective cannot be its own parent.');

                    return;
                }

                $seen = [];
                $cursor = Objective::find($parentId);

                while ($cursor && ! isset($seen[$cursor->id])) {
                    if ($cursor->id === (int) $objectiveId) {
                        $validator->errors()->add(
                            'parent_objective_id',
                            'That parent sits beneath this objective — the cascade would form a loop.'
                        );

                        return;
                    }

                    $seen[$cursor->id] = true;
                    $cursor = $cursor->parent;
                }
            },
        ];
    }
}
