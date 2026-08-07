<?php

namespace Tests\Feature;

use App\Models\SsoConfiguration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SamlService;
use App\Services\SsoService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use OneLogin\Saml2\Utils as SamlUtils;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Tests\TestCase;

class SsoProtocolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->adminA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->adminA->assignRole('System Administrator');
        $this->adminB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->adminB->assignRole('System Administrator');
    }

    // ── OIDC ─────────────────────────────────────────────────────────

    private function activeOidcConfig(): SsoConfiguration
    {
        $config = app(SsoService::class)->create($this->adminA, [
            'protocol' => 'oidc',
            'display_name' => 'Entra ID',
            'oidc_issuer' => 'https://idp.example.test',
            'oidc_client_id' => 'client-123',
            'oidc_client_secret' => 'secret-456',
            'jit_provisioning' => true,
            'is_enabled' => true,
            'attribute_map' => ['email' => 'email', 'name' => 'name'],
        ]);

        return app(SsoService::class)->approve($this->adminB, $config)->fresh();
    }

    private function fakeDiscovery(): void
    {
        Http::fake([
            'idp.example.test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://idp.example.test',
                'authorization_endpoint' => 'https://idp.example.test/authorize',
                'token_endpoint' => 'https://idp.example.test/token',
                'userinfo_endpoint' => 'https://idp.example.test/userinfo',
            ]),
        ]);
    }

    private function idToken(array $claims): string
    {
        $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        // Signature irrelevant: claims arrive over the TLS token channel.
        return $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64($claims).'.sig';
    }

    public function test_oidc_redirect_stores_state_and_nonce_and_sends_the_user_to_the_idp(): void
    {
        $this->fakeDiscovery();
        $config = $this->activeOidcConfig();

        $response = $this->get(route('sso.redirect', $config));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://idp.example.test/authorize', $response->headers->get('Location'));
        $this->assertNotNull(session('oidc.state'));
        $this->assertNotNull(session('oidc.nonce'));
    }

    public function test_oidc_callback_rejects_a_state_mismatch(): void
    {
        $this->fakeDiscovery();
        $config = $this->activeOidcConfig();

        $this->get(route('sso.redirect', $config));

        $this->get(route('sso.callback', [$config, 'code' => 'abc', 'state' => 'tampered-state']))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_oidc_callback_rejects_a_nonce_mismatch(): void
    {
        $this->fakeDiscovery();
        $config = $this->activeOidcConfig();

        $this->get(route('sso.redirect', $config));

        Http::fake([
            'idp.example.test/token' => Http::response([
                'access_token' => 'at',
                'token_type' => 'Bearer',
                'id_token' => $this->idToken([
                    'iss' => 'https://idp.example.test',
                    'aud' => 'client-123',
                    'exp' => now()->addHour()->timestamp,
                    'nonce' => 'wrong-nonce',
                    'email' => 'ada@testbank.ng',
                ]),
            ]),
        ]);

        $this->get(route('sso.callback', [$config, 'code' => 'abc', 'state' => session('oidc.state')]))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_oidc_code_exchange_with_valid_claims_signs_the_user_in(): void
    {
        $this->fakeDiscovery();
        $config = $this->activeOidcConfig();

        $this->get(route('sso.redirect', $config));

        Http::fake([
            'idp.example.test/token' => Http::response([
                'access_token' => 'at',
                'token_type' => 'Bearer',
                'id_token' => $this->idToken([
                    'iss' => 'https://idp.example.test',
                    'aud' => 'client-123',
                    'exp' => now()->addHour()->timestamp,
                    'nonce' => session('oidc.nonce'),
                    'email' => 'ada@testbank.ng',
                    'name' => 'Ada Obi',
                ]),
            ]),
        ]);

        $this->get(route('sso.callback', [$config, 'code' => 'abc', 'state' => session('oidc.state')]))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ada@testbank.ng', 'tenant_id' => $this->tenant->id]);
    }

    public function test_oidc_callback_rejects_an_expired_token(): void
    {
        $this->fakeDiscovery();
        $config = $this->activeOidcConfig();

        $this->get(route('sso.redirect', $config));

        Http::fake([
            'idp.example.test/token' => Http::response([
                'access_token' => 'at',
                'token_type' => 'Bearer',
                'id_token' => $this->idToken([
                    'iss' => 'https://idp.example.test',
                    'aud' => 'client-123',
                    'exp' => now()->subMinute()->timestamp,
                    'nonce' => session('oidc.nonce'),
                    'email' => 'ada@testbank.ng',
                ]),
            ]),
        ]);

        $this->get(route('sso.callback', [$config, 'code' => 'abc', 'state' => session('oidc.state')]))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ── SAML 2.0 ─────────────────────────────────────────────────────

    /** @var array{key: string, cert: string}|null */
    private ?array $keyPair = null;

    private function keyPair(): array
    {
        if ($this->keyPair) {
            return $this->keyPair;
        }

        $resource = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privateKey);

        $csr = openssl_csr_new(['commonName' => 'idp.example.test'], $resource, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $resource, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $cert);

        return $this->keyPair = ['key' => $privateKey, 'cert' => $cert];
    }

    private function activeSamlConfig(): SsoConfiguration
    {
        $config = app(SsoService::class)->create($this->adminA, [
            'protocol' => 'saml2',
            'display_name' => 'Corporate ADFS',
            'entity_id' => 'https://idp.example.test/saml',
            'sso_url' => 'https://idp.example.test/saml/sso',
            'x509_cert' => $this->keyPair()['cert'],
            'jit_provisioning' => true,
            'is_enabled' => true,
            'attribute_map' => ['email' => 'email', 'name' => 'name'],
        ]);

        return app(SsoService::class)->approve($this->adminB, $config)->fresh();
    }

    private function samlResponse(SsoConfiguration $config, string $email, bool $sign = true): string
    {
        $acs = route('sso.acs', $config);
        $spEntityId = route('sso.metadata', $config);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $notAfter = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $notBefore = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
        $responseId = '_'.bin2hex(random_bytes(16));
        $assertionId = '_'.bin2hex(random_bytes(16));

        $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$now}" Destination="{$acs}">
  <saml:Issuer>{$config->entity_id}</saml:Issuer>
  <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>
  <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$assertionId}" Version="2.0" IssueInstant="{$now}">
    <saml:Issuer>{$config->entity_id}</saml:Issuer>
    <saml:Subject>
      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$email}</saml:NameID>
      <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
        <saml:SubjectConfirmationData NotOnOrAfter="{$notAfter}" Recipient="{$acs}"/>
      </saml:SubjectConfirmation>
    </saml:Subject>
    <saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notAfter}">
      <saml:AudienceRestriction><saml:Audience>{$spEntityId}</saml:Audience></saml:AudienceRestriction>
    </saml:Conditions>
    <saml:AuthnStatement AuthnInstant="{$now}" SessionIndex="{$assertionId}">
      <saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext>
    </saml:AuthnStatement>
    <saml:AttributeStatement>
      <saml:Attribute Name="name"><saml:AttributeValue xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="xs:string">Ada Obi</saml:AttributeValue></saml:Attribute>
    </saml:AttributeStatement>
  </saml:Assertion>
</samlp:Response>
XML;

        if ($sign) {
            $xml = SamlUtils::addSign(
                $xml,
                $this->keyPair()['key'],
                $this->keyPair()['cert'],
                XMLSecurityKey::RSA_SHA256,
            );
        }

        return base64_encode($xml);
    }

    /** Point onelogin's superglobal-derived URL at the ACS route. */
    private function primeSamlEnvironment(SsoConfiguration $config, string $samlResponse): void
    {
        $acsPath = parse_url(route('sso.acs', $config), PHP_URL_PATH);

        SamlUtils::setSelfProtocol('http');
        SamlUtils::setSelfHost('localhost');
        SamlUtils::setSelfPort(80);
        $_SERVER['REQUEST_URI'] = $acsPath;
        $_SERVER['SCRIPT_NAME'] = $acsPath;
        $_POST['SAMLResponse'] = $samlResponse;
    }

    protected function tearDown(): void
    {
        unset($_POST['SAMLResponse']);
        parent::tearDown();
    }

    public function test_a_signed_saml_assertion_is_parsed_and_accepted(): void
    {
        $config = $this->activeSamlConfig();

        $this->primeSamlEnvironment($config, $this->samlResponse($config, 'ada@testbank.ng'));

        $claims = app(SamlService::class)->processAssertion($config);

        $this->assertSame('ada@testbank.ng', $claims['email']);
        $this->assertSame('Ada Obi', $claims['name']);
    }

    public function test_an_unsigned_saml_assertion_is_rejected(): void
    {
        $config = $this->activeSamlConfig();

        $this->primeSamlEnvironment($config, $this->samlResponse($config, 'ada@testbank.ng', sign: false));

        $this->expectException(ValidationException::class);

        app(SamlService::class)->processAssertion($config);
    }

    public function test_a_tampered_saml_assertion_fails_signature_validation(): void
    {
        $config = $this->activeSamlConfig();

        $signed = base64_decode($this->samlResponse($config, 'ada@testbank.ng'));
        // Attacker swaps the asserted identity after signing.
        $tampered = str_replace('ada@testbank.ng', 'attacker@testbank.ng', $signed);

        $this->primeSamlEnvironment($config, base64_encode($tampered));

        $this->expectException(ValidationException::class);

        app(SamlService::class)->processAssertion($config);
    }

    public function test_a_replayed_saml_assertion_is_rejected(): void
    {
        $config = $this->activeSamlConfig();
        $response = $this->samlResponse($config, 'ada@testbank.ng');

        $this->primeSamlEnvironment($config, $response);
        app(SamlService::class)->processAssertion($config);

        // Same assertion posted again — replay must be refused.
        $this->primeSamlEnvironment($config, $response);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('replay');

        app(SamlService::class)->processAssertion($config);
    }

    public function test_sp_metadata_endpoint_serves_valid_xml(): void
    {
        $config = $this->activeSamlConfig();

        $response = $this->get(route('sso.metadata', $config));

        $response->assertOk();
        $this->assertStringContainsString('EntityDescriptor', $response->getContent());
        $this->assertStringContainsString(route('sso.acs', $config), $response->getContent());
    }
}
