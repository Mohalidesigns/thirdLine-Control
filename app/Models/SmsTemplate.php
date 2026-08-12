<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS template (15.4). Same catalogue/tenant-override resolution as
 * WhatsappTemplate, without Meta's approval cycle.
 */
class SmsTemplate extends Model
{
    use Auditable;

    protected $fillable = [
        'tenant_id', 'key', 'name', 'body', 'variables', 'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function forKey(string $key, ?int $tenantId = null): ?self
    {
        return static::query()
            ->where('key', $key)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null')
            ->first();
    }

    public function render(array $variables): string
    {
        $body = $this->body;

        foreach ($this->variables ?? [] as $name) {
            $body = str_replace('{{'.$name.'}}', (string) ($variables[$name] ?? ''), $body);
        }

        return $body;
    }
}
