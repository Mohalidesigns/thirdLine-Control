<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CheckItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'test_script_id', 'sequence', 'question', 'guidance', 'expected_result',
        'is_mandatory', 'default_severity_on_fail',
        // CR-03 §C.2 — a checklist line can carry its own rhythm. NULL is
        // the overwhelming majority (1,483 of the client's 1,517 lines)
        // and means "inherit the control's frequency".
        'frequency_id', 'frequency_raw', 'source_ref',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_mandatory' => 'boolean',
    ];

    public function testScript(): BelongsTo
    {
        return $this->belongsTo(TestScript::class);
    }

    public function controlFrequency(): BelongsTo
    {
        return $this->belongsTo(ControlFrequency::class, 'frequency_id');
    }

    /**
     * CR-03 §C.2: the rhythm this line is executed at — its own override
     * where one is set, the control's otherwise. This is what makes one
     * NOSTRO checklist produce a daily instance of eleven lines and a
     * monthly instance of five.
     */
    public function effectiveFrequencyId(?int $controlFrequencyId): ?int
    {
        return $this->frequency_id ?: $controlFrequencyId;
    }
}
