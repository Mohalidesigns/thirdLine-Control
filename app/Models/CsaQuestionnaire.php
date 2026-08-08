<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsaQuestionnaire extends Model
{
    use Auditable, HasFactory, HasVersions;

    public const SCORING_METHODS = ['none', 'weighted', 'maturity'];

    protected $fillable = [
        'campaign_id', 'name', 'version_no', 'is_active', 'instructions',
        'scoring_method', 'pass_threshold',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'is_active' => 'boolean',
        'pass_threshold' => 'decimal:2',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CsaCampaign::class, 'campaign_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CsaQuestion::class, 'questionnaire_id')
            ->orderBy('sequence');
    }
}
