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
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_mandatory' => 'boolean',
    ];

    public function testScript(): BelongsTo
    {
        return $this->belongsTo(TestScript::class);
    }
}
