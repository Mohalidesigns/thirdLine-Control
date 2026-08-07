<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CheckResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_instance_id', 'check_item_id', 'result', 'comment', 'tested_by', 'tested_at',
    ];

    protected $casts = [
        'tested_at' => 'datetime',
    ];

    public function testInstance(): BelongsTo
    {
        return $this->belongsTo(TestInstance::class);
    }

    public function checkItem(): BelongsTo
    {
        return $this->belongsTo(CheckItem::class);
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    public function exception(): HasOne
    {
        return $this->hasOne(ControlException::class, 'check_result_id');
    }
}
