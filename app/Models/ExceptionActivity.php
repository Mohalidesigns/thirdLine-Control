<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'exception_id', 'activity_type', 'actor_id', 'from_value', 'to_value', 'note',
    ];

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ControlException::class, 'exception_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
