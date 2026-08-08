<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsaAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'response_id', 'question_id', 'answer_value', 'comment', 'evidence_id', 'answered_at',
    ];

    protected $casts = [
        'answer_value' => 'array',
        'answered_at' => 'datetime',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(CsaResponse::class, 'response_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CsaQuestion::class, 'question_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class, 'evidence_id');
    }
}
