<?php

namespace App\Services\Messaging;

use App\Models\MessageLog;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Web Push over VAPID (15.5), deliberately payload-free: an empty push
 * wakes the service worker, which fetches the user's unread notifications
 * over the authenticated session and shows the newest one. No payload
 * means no RFC 8291 message encryption to maintain AND no notification
 * content resting on a push relay — the R5-friendly shape. Unconfigured
 * VAPID keys skip quietly, like every Phase 15 channel.
 */
class WebPushService
{
    public function subscribe(User $user, array $subscription): PushSubscription
    {
        $endpoint = (string) $subscription['endpoint'];

        return PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'p256dh' => $subscription['keys']['p256dh'] ?? null,
                'auth' => $subscription['keys']['auth'] ?? null,
                'failed_at' => null,
            ],
        );
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();
    }

    public function isConfigured(): bool
    {
        return (bool) (config('messaging.push.vapid_public_key')
            && config('messaging.push.vapid_private_key'));
    }

    /** @return int pushes sent */
    public function sendToUser(User $user, array $meta = []): int
    {
        $subscriptions = PushSubscription::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->whereNull('failed_at')
            ->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        if (! $this->isConfigured()) {
            MessageLog::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'channel' => 'push',
                'direction' => 'out',
                'status' => 'skipped',
                'error' => 'Web Push not configured — set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.',
                'meta' => $meta,
            ]);

            return 0;
        }

        $sent = 0;

        foreach ($subscriptions as $subscription) {
            $sent += $this->push($subscription, $meta) ? 1 : 0;
        }

        return $sent;
    }

    private function push(PushSubscription $subscription, array $meta): bool
    {
        $log = fn (array $attributes) => MessageLog::create([
            'tenant_id' => $subscription->tenant_id,
            'user_id' => $subscription->user_id,
            'channel' => 'push',
            'direction' => 'out',
            'meta' => $meta,
            ...$attributes,
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'vapid t='.$this->vapidJwt($subscription->endpoint)
                    .', k='.config('messaging.push.vapid_public_key'),
                'TTL' => (string) config('messaging.push.ttl', 86400),
            ])->send('POST', $subscription->endpoint);
        } catch (Throwable $e) {
            $log(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500)]);

            return false;
        }

        // The push service says this device is gone — stop trying.
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->update(['failed_at' => now()]);
            $log(['status' => 'failed', 'error' => 'Subscription expired (HTTP '.$response->status().').']);

            return false;
        }

        if ($response->failed()) {
            $log(['status' => 'failed', 'error' => 'HTTP '.$response->status()]);

            return false;
        }

        $log(['status' => 'sent']);

        return true;
    }

    /**
     * ES256-signed VAPID token (RFC 8292). The private key is a PEM (or
     * base64-encoded PEM, .env-friendly) P-256 key; openssl signs, and the
     * DER signature is converted to the raw r||s form JWTs require.
     */
    private function vapidJwt(string $endpoint): string
    {
        $origin = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);

        $segment = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        $signingInput = $segment(['typ' => 'JWT', 'alg' => 'ES256']).'.'.$segment([
            'aud' => $origin,
            'exp' => now()->addHours(12)->timestamp,
            'sub' => config('messaging.push.vapid_subject'),
        ]);

        $pem = (string) config('messaging.push.vapid_private_key');

        if (! str_contains($pem, 'BEGIN')) {
            $pem = (string) base64_decode($pem, true);
        }

        $key = openssl_pkey_get_private($pem);

        if ($key === false || ! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID_PRIVATE_KEY is not a usable P-256 private key.');
        }

        return $signingInput.'.'.rtrim(strtr(base64_encode($this->derToRaw($der)), '+/', '-_'), '=');
    }

    /** DER ECDSA signature → fixed-width 64-byte r||s. */
    private function derToRaw(string $der): string
    {
        $offset = 2; // SEQUENCE header
        $extract = function () use ($der, &$offset): string {
            $offset++; // INTEGER tag
            $length = ord($der[$offset++]);
            $value = substr($der, $offset, $length);
            $offset += $length;

            return str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $extract().$extract();
    }
}
