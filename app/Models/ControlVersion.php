<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'control_id', 'version_no', 'payload', 'effective_from', 'effective_to',
        'changed_by', 'change_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
