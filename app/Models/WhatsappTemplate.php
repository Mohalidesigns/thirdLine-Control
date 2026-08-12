<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta-approved WhatsApp message template (15.3). tenant_id NULL rows are
 * the platform catalogue; a tenant row with the same key overrides it —
 * the NotificationEvent pattern, NOT BelongsToTenant, because resolution
 * needs both scopes.
 */
class WhatsappTemplate extends Model
{
    use Auditable;

    public const APPROVAL_STATUSES = ['draft', 'pending', 'approved', 'rejected'];

    protected $fillable = [
        'tenant_id', 'key', 'name', 'language', 'category', 'body',
        'variables', 'meta_template_id', 'approval_status', 'synced_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'synced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Tenant override first, platform catalogue row second. */
    public static function forKey(string $key, ?int $tenantId = null, string $language = 'en'): ?self
    {
        return static::query()
            ->where('key', $key)
            ->where('language', $language)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null')
            ->first();
    }

    /** Only Meta-approved templates may ever be sent out-of-session. */
    public function isSendable(): bool
    {
        return $this->approval_status === 'approved';
    }

    /** Substitute {{name}} placeholders in declared order. */
    public function render(array $variables): string
    {
        $body = $this->body;

        foreach ($this->variables ?? [] as $name) {
            $body = str_replace('{{'.$name.'}}', (string) ($variables[$name] ?? ''), $body);
        }

        return $body;
    }
}
