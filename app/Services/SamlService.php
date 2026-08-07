<?php

namespace App\Services;

use App\Models\SsoConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Settings;

/**
 * SAML 2.0 service-provider side (onelogin/php-saml): AuthnRequest
 * redirect, assertion consumption with signature validation, replay
 * rejection via assertion-ID cache, and SP metadata.
 */
class SamlService
{
    /** Assertion IDs are remembered for this long to reject replays. */
    private const REPLAY_TTL_HOURS = 12;

    public function authnRequestUrl(SsoConfiguration $config, bool $testMode = false): string
    {
        session(['saml.config_id' => $config->id, 'saml.test' => $testMode]);

        $auth = new SamlAuth($this->settings($config));

        // stay=true returns the redirect URL instead of emitting a header.
        return $auth->login(null, [], false, false, true);
    }

    /**
     * Validate the posted SAMLResponse: signature (against the configured
     * IdP certificate), audience/destination, expiry — all enforced by
     * onelogin — plus replay rejection on the assertion ID.
     *
     * @return array<string, mixed> asserted attributes + nameId as 'email' fallback
     */
    public function processAssertion(SsoConfiguration $config): array
    {
        $auth = new SamlAuth($this->settings($config));

        $auth->processResponse();

        if ($auth->getErrors() !== []) {
            throw ValidationException::withMessages([
                'email' => 'SAML assertion rejected: '.implode(', ', $auth->getErrors()),
            ]);
        }

        if (! $auth->isAuthenticated()) {
            throw ValidationException::withMessages(['email' => 'SAML assertion did not authenticate.']);
        }

        $assertionId = $auth->getLastAssertionId();

        // Cache::add is atomic: false means the ID was already consumed.
        if (! Cache::add("saml:assertion:{$assertionId}", 1, now()->addHours(self::REPLAY_TTL_HOURS))) {
            throw ValidationException::withMessages(['email' => 'SAML assertion replay rejected.']);
        }

        $claims = collect($auth->getAttributes())
            ->map(fn ($values) => count($values) === 1 ? $values[0] : $values)
            ->all();

        $claims['email'] ??= $auth->getNameId();

        return $claims;
    }

    public function metadataXml(SsoConfiguration $config): string
    {
        $settings = new Settings($this->settings($config), true);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);

        if ($errors !== []) {
            throw new \RuntimeException('Invalid SP metadata: '.implode(', ', $errors));
        }

        return $metadata;
    }

    /**
     * "Test connection" for SAML validates the stored IdP certificate and
     * settings without creating a session.
     */
    public function testConnection(SsoConfiguration $config): array
    {
        try {
            new Settings($this->settings($config));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Settings invalid: '.$e->getMessage()];
        }

        $cert = openssl_x509_parse($this->pemCert($config));

        if ($cert === false) {
            return ['ok' => false, 'message' => 'The IdP X.509 certificate could not be parsed.'];
        }

        if (($cert['validTo_time_t'] ?? 0) < now()->timestamp) {
            return ['ok' => false, 'message' => 'The IdP X.509 certificate has expired ('.date('Y-m-d', $cert['validTo_time_t']).').'];
        }

        return ['ok' => true, 'message' => 'Settings valid. IdP certificate expires '.date('Y-m-d', $cert['validTo_time_t']).'.'];
    }

    private function settings(SsoConfiguration $config): array
    {
        return [
            'strict' => true,
            'sp' => [
                'entityId' => route('sso.metadata', $config),
                'assertionConsumerService' => [
                    'url' => route('sso.acs', $config),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ],
            'idp' => [
                'entityId' => $config->entity_id,
                'singleSignOnService' => [
                    'url' => $config->sso_url,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => $config->slo_url ? [
                    'url' => $config->slo_url,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ] : [],
                'x509cert' => $config->x509_cert,
            ],
            'security' => [
                'wantAssertionsSigned' => false,
                'wantMessagesSigned' => false,
                // At least one of message/assertion must carry a valid
                // signature — enforced by onelogin with strict=true.
                'wantXMLValidation' => true,
                'requestedAuthnContext' => false,
            ],
        ];
    }

    private function pemCert(SsoConfiguration $config): string
    {
        $cert = trim((string) $config->x509_cert);

        if (! str_contains($cert, 'BEGIN CERTIFICATE')) {
            $cert = "-----BEGIN CERTIFICATE-----\n".chunk_split($cert, 64, "\n").'-----END CERTIFICATE-----';
        }

        return $cert;
    }
}
