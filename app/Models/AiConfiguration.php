<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Ai\CapabilityRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class AiConfiguration extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'capability_key', 'is_enabled', 'model', 'temperature',
        'max_tokens', 'system_prompt_id', 'monthly_token_budget',
        'requires_approval_role_id', 'min_confidence_to_surface',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'monthly_token_budget' => 'integer',
        'min_confidence_to_surface' => 'float',
        'approved_at' => 'datetime',
    ];

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'system_prompt_id');
    }

    public function approvalRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'requires_approval_role_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The capability's static definition from config/ai.php. */
    public function definition(): array
    {
        return CapabilityRegistry::definition($this->capability_key);
    }
}
