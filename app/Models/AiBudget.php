<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiBudget extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'period_label', 'period_start', 'period_end',
        'token_budget', 'tokens_used', 'cost_budget_minor', 'cost_used_minor',
        'currency', 'hard_stop', 'alert_thresholds', 'alerted_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'token_budget' => 'integer',
        'tokens_used' => 'integer',
        'cost_budget_minor' => 'integer',
        'cost_used_minor' => 'integer',
        'hard_stop' => 'boolean',
        'alert_thresholds' => 'array',
        'alerted_at' => 'array',
    ];

    /**
     * True when either ceiling is reached. Both are checked because they
     * fail in different directions: a cheap model can exhaust tokens long
     * before cost, and an expensive one the reverse.
     */
    public function isExhausted(): bool
    {
        return ($this->token_budget > 0 && $this->tokens_used >= $this->token_budget)
            || ($this->cost_budget_minor > 0 && $this->cost_used_minor >= $this->cost_budget_minor);
    }

    public function tokenPercentUsed(): float
    {
        return $this->token_budget > 0
            ? round(($this->tokens_used / $this->token_budget) * 100, 1)
            : 0.0;
    }

    public function costPercentUsed(): float
    {
        return $this->cost_budget_minor > 0
            ? round(($this->cost_used_minor / $this->cost_budget_minor) * 100, 1)
            : 0.0;
    }

    /** The higher of the two, which is the one that will stop the tenant. */
    public function percentUsed(): float
    {
        return max($this->tokenPercentUsed(), $this->costPercentUsed());
    }
}
