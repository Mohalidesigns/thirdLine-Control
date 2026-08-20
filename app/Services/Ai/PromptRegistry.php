<?php

namespace App\Services\Ai;

use App\Models\AiPrompt;
use App\Services\Ai\Exceptions\CapabilityDisabledException;
use Illuminate\Support\Collection;

/**
 * Resolves and renders versioned prompts (Part C §C.4 step 7).
 *
 * Resolution order is tenant override, then shipped library, highest active
 * version first. A tenant can therefore reword a prompt for its own
 * regulator without forking the product, and pin a version by deactivating
 * the newer one (R1).
 */
class PromptRegistry
{
    /** @var array<string, AiPrompt|null> memoised per request */
    private array $resolved = [];

    /**
     * The active prompt for a capability. Explicit $version pins a
     * historical one — used when re-running an interaction for comparison,
     * never in the normal path.
     */
    public function resolve(string $key, ?int $tenantId, ?int $version = null): AiPrompt
    {
        $cacheKey = $key.':'.($tenantId ?? 'global').':'.($version ?? 'active');

        if (array_key_exists($cacheKey, $this->resolved) && $this->resolved[$cacheKey]) {
            return $this->resolved[$cacheKey];
        }

        $prompt = AiPrompt::query()
            ->visibleTo($tenantId)
            ->where('key', $key)
            ->when($version, fn ($q) => $q->where('version', $version))
            ->when(! $version, fn ($q) => $q->where('is_active', true))
            // Tenant rows win over the shipped library; within either, the
            // highest version wins.
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')
            ->first();

        if (! $prompt) {
            throw new CapabilityDisabledException(
                "No active prompt is registered for '{$key}'.",
            );
        }

        return $this->resolved[$cacheKey] = $prompt;
    }

    /**
     * Substitute variables into the user template.
     *
     * Values arrive already redacted — this is step 7 of the pipeline and
     * redaction is step 6, so anything reaching here has been through it.
     * Arrays are rendered as JSON so a list of retrieved records keeps its
     * structure for the model to cite from.
     *
     * A declared variable with no supplied value renders empty rather than
     * leaving a literal `{{name}}` in the prompt, which the model would
     * otherwise try to interpret.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(AiPrompt $prompt, array $variables): string
    {
        $declared = (array) ($prompt->variables ?? []);
        $keys = $declared !== [] ? $declared : array_keys($variables);

        $replacements = [];

        foreach ($keys as $name) {
            $value = $variables[$name] ?? '';

            $replacements['{{'.$name.'}}'] = match (true) {
                is_array($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                is_bool($value) => $value ? 'true' : 'false',
                is_null($value) => '',
                default => (string) $value,
            };
        }

        return strtr($prompt->user_template, $replacements);
    }

    /**
     * Every version of a key, newest first — the prompt history view on the
     * governance page.
     *
     * @return Collection<int, AiPrompt>
     */
    public function history(string $key, ?int $tenantId): Collection
    {
        return AiPrompt::query()
            ->visibleTo($tenantId)
            ->where('key', $key)
            ->with(['creator:id,name', 'approver:id,name'])
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Create the next version of a prompt as a tenant override.
     *
     * Never edits an existing row: ai_interactions references the version
     * that actually ran, so rewriting one would make an audited interaction
     * unreconstructible (R3). Activating the new version deactivates the
     * tenant's previous one, leaving the shipped library untouched.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function publish(string $key, int $tenantId, array $attributes, int $userId): AiPrompt
    {
        $latest = AiPrompt::query()
            ->visibleTo($tenantId)
            ->where('key', $key)
            ->orderByDesc('version')
            ->first();

        $prompt = AiPrompt::create([
            'tenant_id' => $tenantId,
            'key' => $key,
            'version' => ($latest?->version ?? 0) + 1,
            'name' => $attributes['name'] ?? $latest?->name ?? $key,
            'description' => $attributes['description'] ?? $latest?->description,
            'system_prompt' => $attributes['system_prompt'] ?? $latest?->system_prompt ?? '',
            'user_template' => $attributes['user_template'] ?? $latest?->user_template ?? '',
            'output_schema' => $attributes['output_schema'] ?? $latest?->output_schema,
            'variables' => $attributes['variables'] ?? $latest?->variables,
            'model_hint' => $attributes['model_hint'] ?? $latest?->model_hint,
            'temperature' => $attributes['temperature'] ?? $latest?->temperature,
            'max_tokens' => $attributes['max_tokens'] ?? $latest?->max_tokens,
            'is_active' => false,
            'created_by' => $userId,
            'changelog' => $attributes['changelog'] ?? null,
        ]);

        $this->resolved = [];

        return $prompt;
    }

    /**
     * Make a version the active one for this tenant. Deactivates the
     * tenant's other versions of the same key; the shipped library row stays
     * as it is and simply loses the resolution race.
     */
    public function activate(AiPrompt $prompt, int $tenantId, int $userId): AiPrompt
    {
        AiPrompt::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $prompt->key)
            ->where('id', '!=', $prompt->id)
            ->update(['is_active' => false]);

        $prompt->update([
            'is_active' => true,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $prompt->auditAction('prompt_activated', null, [
            'key' => $prompt->key,
            'version' => $prompt->version,
        ]);

        $this->resolved = [];

        return $prompt;
    }
}
