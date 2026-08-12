<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidencyAttestation extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    protected $fillable = [
        'tenant_id', 'reference', 'declared_country', 'period_start', 'period_end',
        'content', 'checksum', 'signature', 'generated_by', 'generated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'content' => 'array',
        'generated_at' => 'datetime',
    ];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** True when the stored body still matches its recorded signature. */
    public function verifySignature(): bool
    {
        $checksum = hash('sha256', json_encode($this->content));

        return hash_equals($this->checksum, $checksum)
            && hash_equals($this->signature, hash_hmac('sha256', $checksum, config('app.key')));
    }
}
