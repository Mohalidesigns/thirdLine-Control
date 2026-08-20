<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompensatingControl extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, SoftDeletes;

    public const STATUSES = ['Proposed', 'Approved', 'Active', 'Expired', 'Withdrawn'];

    protected $fillable = [
        'tenant_id', 'exception_id', 'primary_control_id', 'description', 'owner_id',
        'is_temporary', 'effective_from', 'effective_to', 'residual_exposure_note',
        'status', 'approved_by', 'approved_at', 'linked_control_id',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['description', 'residual_exposure_note'];

    protected $casts = [
        'is_temporary' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
    ];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function primaryControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'primary_control_id');
    }

    public function linkedControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'linked_control_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
