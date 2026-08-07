<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Finding extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'spot_check_id', 'control_id', 'observation', 'severity', 'risk_implication',
        'recommendation', 'management_response', 'agreed_action',
        'responsible_party_id', 'target_date',
    ];

    protected $casts = [
        'target_date' => 'date',
    ];

    public function spotCheck(): BelongsTo
    {
        return $this->belongsTo(SpotCheck::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function responsibleParty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_party_id');
    }

    public function exception(): HasOne
    {
        return $this->hasOne(ControlException::class, 'source_id')
            ->where('source_type', 'Spot Check');
    }
}
