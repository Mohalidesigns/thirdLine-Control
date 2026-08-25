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
        'period_year' => 'integer',
    ];

    /**
     * CR-03 §C.7: period_year is DERIVED from the instance, never
     * assigned. It is the partition and retention key on what becomes
     * the largest table in the platform — roughly 28 million rows a year
     * at the client's branch count — and a hand-written value that
     * disagreed with the instance would put a result in the wrong
     * partition and out of reach of the sweep that should retire it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $result) {
            if ($result->period_year !== null || ! $result->test_instance_id) {
                return;
            }

            // A value lookup, not a hydrated relation: this fires once per
            // recorded checklist line, and at the client's volumes that is
            // millions of rows a year.
            $result->period_year = $result->relationLoaded('testInstance')
                ? $result->testInstance?->period_year
                : TestInstance::withoutGlobalScopes()
                    ->whereKey($result->test_instance_id)
                    ->value('period_year');
        });
    }

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
