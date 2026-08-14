<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssuranceProvider extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const LINES = [
        'management', 'second_line', 'internal_audit',
        'external_audit', 'regulator', 'external_assurance',
    ];

    public const LINE_LABELS = [
        'management' => 'Management (first line)',
        'second_line' => 'Second line',
        'internal_audit' => 'Internal audit (third line)',
        'external_audit' => 'External audit',
        'regulator' => 'Regulator',
        'external_assurance' => 'External assurance',
    ];

    /**
     * Lines whose work a board may place formal reliance on without a
     * separate independence judgement. Management's assurance over its own
     * process is self-assessment, which is worth having and is not the
     * same thing — the map shows it as coverage but never as independent
     * assurance.
     */
    public const INDEPENDENT_LINES = ['internal_audit', 'external_audit', 'regulator', 'external_assurance'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'line', 'description', 'contact_name',
        'contact_email', 'is_independent', 'integration_config_id', 'is_active',
    ];

    protected $casts = [
        'is_independent' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(AssuranceActivity::class, 'provider_id');
    }

    public function integrationConfig(): BelongsTo
    {
        return $this->belongsTo(IntegrationConfig::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function lineLabel(): string
    {
        return self::LINE_LABELS[$this->line] ?? $this->line;
    }

    /** Independence is a recorded judgement; the line is only its default. */
    public function countsAsIndependent(): bool
    {
        return $this->is_independent || in_array($this->line, self::INDEPENDENT_LINES, true);
    }
}
