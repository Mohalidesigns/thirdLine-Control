<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsaQuestion extends Model
{
    use HasFactory;

    public const RESPONSE_TYPES = [
        'yes_no', 'yes_no_na', 'scale_1_5', 'maturity_0_5', 'single_select',
        'multi_select', 'free_text', 'numeric', 'date', 'file_upload',
    ];

    protected $fillable = [
        'questionnaire_id', 'section', 'sequence', 'question_text', 'help_text',
        'response_type', 'options', 'is_required', 'evidence_required', 'weight',
        'control_id', 'requirement_id', 'triggers_exception_on', 'condition',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'options' => 'array',
        'is_required' => 'boolean',
        'evidence_required' => 'boolean',
        'weight' => 'decimal:2',
        'triggers_exception_on' => 'array',
        'condition' => 'array',
    ];

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(CsaQuestionnaire::class, 'questionnaire_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CsaAnswer::class, 'question_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    /**
     * Conditional visibility: hidden until the referenced question's answer
     * matches. condition = {"question_id": <id>, "in": [values…]}.
     *
     * @param  array<int, mixed>  $answersByQuestionId
     */
    public function isVisible(array $answersByQuestionId): bool
    {
        if (! $this->condition || empty($this->condition['question_id'])) {
            return true;
        }

        $answer = $answersByQuestionId[$this->condition['question_id']] ?? null;
        $expected = (array) ($this->condition['in'] ?? []);

        foreach ((array) $answer as $value) {
            if (in_array($value, $expected, false)) {
                return true;
            }
        }

        return false;
    }

    /** Does an answer match this question's exception trigger (9.3)? */
    public function triggersException(mixed $answer): bool
    {
        if (! $this->triggers_exception_on) {
            return false;
        }

        $matches = (array) ($this->triggers_exception_on['in'] ?? $this->triggers_exception_on);

        foreach ((array) $answer as $value) {
            if (in_array($value, $matches, false)) {
                return true;
            }
        }

        return false;
    }

    /** Numeric score contribution for weighted / maturity scoring. */
    public function scoreValue(mixed $answer): ?float
    {
        $value = is_array($answer) ? ($answer[0] ?? null) : $answer;

        return match ($this->response_type) {
            'yes_no', 'yes_no_na' => match ($value) {
                'Yes' => 1.0, 'No' => 0.0, default => null,
            },
            'scale_1_5' => is_numeric($value) ? (float) $value / 5 : null,
            'maturity_0_5' => is_numeric($value) ? (float) $value / 5 : null,
            'numeric' => is_numeric($value) ? (float) $value : null,
            default => null,
        };
    }
}
