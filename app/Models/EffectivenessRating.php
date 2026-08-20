<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EffectivenessRating extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, SoftDeletes;

    public const SCALE = ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested'];

    protected $fillable = [
        'tenant_id', 'test_instance_id', 'control_id', 'period_label',
        'design_effectiveness', 'design_rationale',
        'operating_effectiveness', 'operating_rationale',
        'overall_rating', 'status', 'rated_by', 'approved_by', 'approved_at',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['design_rationale', 'operating_rationale'];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function testInstance(): BelongsTo
    {
        return $this->belongsTo(TestInstance::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
