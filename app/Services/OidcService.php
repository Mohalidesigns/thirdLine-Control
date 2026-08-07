<?php

namespace App\Services;

use App\Models\SsoConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OIDC authorization-code flow (Entra ID / Azure AD priority, plus Okta
 * and any conformant IdP). State and nonce are bound to the session; the
 * ID token is obtained directly from the token endpoint over TLS and its
 * iss/aud/exp/nonce claims are all validated.
 */
class OidcService
{
    private const DISCOVERY_CACHE_MINUTES = 60;

    public function authorizationUrl(SsoConfiguration $config, bool $testMode = false): string
    {
        $document = $this->discoveryDocument($config);
        $state = Str::random(40);
        $nonce = Str::random(40);

        session([
            'oidc.state' => $state,
            'oidc.nonce' => $nonce,
            'oidc.config_id' => $config->id,
            'oidc.test' => $testMode,
        ]);

        return $document['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $config->oidc_client_id,
            'redirect_uri' => route('sso.callback', $config),
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }

    /** @return array<string, mixed> validated ID-token claims */
    public function handleCallback(SsoConfiguration $config, Request $request): array
    {
        if ($request->filled('error')) {
            throw ValidationException::withMessages(['email' => 'The identity provider returned an error: '.$request->input('error')]);
        }

        if (! $request->filled('state')
            || ! hash_equals((string) session('oidc.state'), (string) $request->input('state'))
            || (int) session('oidc.config_id') !== $config->id) {
            throw ValidationException::withMessages(['email' => 'Invalid SSO state — possible cross-site request. Start the sign-in again.']);
        }

        $document = $this->discoveryDocument($config);

        $token = Http::asForm()->timeout(15)->post($document['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'redirect_uri' => route('sso.callback', $config),
            'client_id' => $config->oidc_client_id,
            'client_secret' => $config->oidc_client_secret,
        ])->throw()->json();

        $idToken = $token['id_token'] ?? null;

        if (! $idToken) {
            throw ValidationException::withMessages(['email' => 'The identity provider did not return an ID token.']);
        }

        $claims = $this->decodeIdToken($idToken);

        $this->validateClaims($config, $claims);

        session()->forget(['oidc.state', 'oidc.nonce']);

        return $claims;
    }

    /**
     * Reachability + discovery validation for the admin "Test connection"
     * action — no session is created.
     */
    public function testConnection(SsoConfiguration $config): array
    {
        $document = $this->discoveryDocument($config, fresh: true);

        $missing = array_diff(['authorization_endpoint', 'token_endpoint', 'issuer'], array_keys($document));

        if ($missing !== []) {
            return ['ok' => false, 'message' => 'Discovery document is missing: '.implode(', ', $missing)];
        }

        if ($config->oidc_issuer && $document['issuer'] !== $config->oidc_issuer) {
            return ['ok' => false, 'message' => "Issuer mismatch: discovery says '{$document['issuer']}'."];
        }

        return ['ok' => true, 'message' => 'Discovery document valid. Issuer: '.$document['issuer']];
    }

    private function discoveryDocument(SsoConfiguration $config, bool $fresh = false): array
    {
        $url = $config->oidc_discovery_url
            ?: rtrim((string) $config->oidc_issuer, '/').'/.well-known/openid-configuration';

        $fetch = fn () => Http::timeout(10)->get($url)->throw()->json();

        if ($fresh) {
            $document = $fetch();
            Cache::put("oidc:discovery:{$config->id}", $document, now()->addMinutes(self::DISCOVERY_CACHE_MINUTES));

            return $document;
        }

        return Cache::remember("oidc:discovery:{$config->id}", now()->addMinutes(self::DISCOVERY_CACHE_MINUTES), $fetch);
    }

    /**
     * The token was received directly from the IdP's token endpoint over
     * TLS (OIDC Core §3.1.3.7 allows relying on that channel), so claim
     * validation is what remains: iss, aud, exp, and the session nonce.
     */
    private function decodeIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw ValidationException::withMessages(['email' => 'Malformed ID token.']);
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['email' => 'Unreadable ID token payload.']);
        }

        return $payload;
    }

    private function validateClaims(SsoConfiguration $config, array $claims): void
    {
        $failures = [];

        if ($config->oidc_issuer && ($claims['iss'] ?? null) !== $config->oidc_issuer) {
            $failures[] = 'issuer mismatch';
        }

        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($config->oidc_client_id, $aud, true)) {
            $failures[] = 'audience mismatch';
        }

        if (($claims['exp'] ?? 0) < now()->timestamp) {
            $failures[] = 'token expired';
        }

        if (! hash_equals((string) session('oidc.nonce'), (string) ($claims['nonce'] ?? ''))) {
            $failures[] = 'nonce mismatch';
        }

        if ($failures !== []) {
            throw ValidationException::withMessages(['email' => 'ID token rejected: '.implode(', ', $failures).'.']);
        }
    }
}
