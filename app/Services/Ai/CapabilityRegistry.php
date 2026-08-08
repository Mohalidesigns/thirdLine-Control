<?php

namespace App\Services\Ai;

/**
 * Reads config/ai.php's capability definitions.
 *
 * This exists because capability keys contain a dot — `control.draft`,
 * `atlas.chat` — and Laravel's config() helper treats a dot as a nesting
 * separator. `config('ai.capabilities.control.draft.permission')` therefore
 * looks for `capabilities → control → draft → permission` and quietly
 * returns null.
 *
 * Quietly is the problem. A null permission would have made the gateway's
 * authorisation check pass for everyone, which is the worst possible way for
 * a configuration lookup to fail. Every read of a capability definition goes
 * through here, using plain array access, so the dot stays part of the key.
 */
final class CapabilityRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return (array) config('ai.capabilities', []);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<string, mixed> */
    public static function definition(string $key): array
    {
        return (array) (self::all()[$key] ?? []);
    }

    public static function get(string $key, string $field, mixed $default = null): mixed
    {
        return self::definition($key)[$field] ?? $default;
    }

    /**
     * The domain permission a user must already hold. Null is returned only
     * for a key that does not exist, and callers must treat that as "refuse",
     * never as "no permission required".
     */
    public static function permission(string $key): ?string
    {
        $permission = self::get($key, 'permission');

        return is_string($permission) && $permission !== '' ? $permission : null;
    }

    public static function label(string $key): string
    {
        return (string) self::get($key, 'label', $key);
    }

    public static function tier(string $key): string
    {
        return (string) self::get($key, 'tier', 'default');
    }

    /** @return array<int, string> */
    public static function sources(string $key): array
    {
        return (array) self::get($key, 'sources', []);
    }

    public static function rateLimit(string $key): int
    {
        return (int) self::get($key, 'rate', 10);
    }

    public static function description(string $key): ?string
    {
        $description = self::get($key, 'description');

        return is_string($description) ? $description : null;
    }
}
