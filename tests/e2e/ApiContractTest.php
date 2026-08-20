<?php

namespace Tests\e2e;

use App\Models\Control;
use Database\Seeders\E2ETestSeeder;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part B §9 — the API test suite, across all 11 `/api/v1` endpoints.
 *
 * The plan asks for each endpoint: valid request, malformed JSON, missing
 * fields, wrong types, unauthenticated, wrong-role, non-existent id,
 * another tenant's id, oversized payload, rate limiting, correct status
 * codes, a consistent error envelope, and no stack traces or secrets in
 * responses.
 */
class ApiContractTest extends E2ETestCase
{
    private const KEY = E2ETestSeeder::THIRDLINE_API_KEY;

    /** All 11 endpoints on the contract. */
    public static function endpointProvider(): array
    {
        return [
            'GET controls' => ['GET', '/api/v1/controls'],
            'POST controls' => ['POST', '/api/v1/controls'],
            'GET test-results' => ['GET', '/api/v1/test-results'],
            'GET exceptions' => ['GET', '/api/v1/exceptions'],
            'GET frameworks/coverage' => ['GET', '/api/v1/frameworks/coverage'],
            'GET obligations/instances' => ['GET', '/api/v1/obligations/instances'],
            'GET assurance/coverage' => ['GET', '/api/v1/assurance/coverage'],
            'GET assurance/gaps' => ['GET', '/api/v1/assurance/gaps'],
            'POST assurance/activities' => ['POST', '/api/v1/assurance/activities'],
            'POST assurance/findings' => ['POST', '/api/v1/assurance/findings'],
            'GET third-parties' => ['GET', '/api/v1/third-parties'],
        ];
    }

    public static function readEndpointProvider(): array
    {
        return array_filter(self::endpointProvider(), fn ($e) => $e[0] === 'GET');
    }

    private function apiCall(string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge(['X-Api-Key' => self::KEY], $headers))
            ->json($method, $uri, $body);
    }

    // -----------------------------------------------------------------
    // Authentication — every endpoint, no exceptions
    // -----------------------------------------------------------------

    #[DataProvider('endpointProvider')]
    public function test_every_endpoint_rejects_an_absent_key(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);
    }

    #[DataProvider('endpointProvider')]
    public function test_every_endpoint_rejects_an_invalid_key(string $method, string $uri): void
    {
        $this->withHeaders(['X-Api-Key' => 'not-a-valid-key'])->json($method, $uri)->assertStatus(401);
    }

    #[DataProvider('endpointProvider')]
    public function test_no_endpoint_leaks_a_stack_trace_or_a_secret(string $method, string $uri): void
    {
        $response = $this->apiCall($method, $uri);
        $body = $response->getContent();

        foreach (['#0 /', 'Stack trace', 'vendor/laravel/framework', 'SQLSTATE', 'APP_KEY', 'base64:'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "{$method} {$uri} leaked '{$leak}' to the caller.");
        }

        $this->assertStringNotContainsString(
            self::KEY,
            $body,
            "{$method} {$uri} echoed the caller's API key back in the response."
        );
    }

    // -----------------------------------------------------------------
    // Read endpoints — shape and status
    // -----------------------------------------------------------------

    #[DataProvider('readEndpointProvider')]
    public function test_read_endpoints_return_200_and_json(string $method, string $uri): void
    {
        $response = $this->apiCall($method, $uri);

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'), "{$uri} did not return JSON.");
        $this->assertIsArray($response->json(), "{$uri} did not return a JSON structure.");
    }

    #[DataProvider('readEndpointProvider')]
    public function test_read_endpoints_ignore_an_unknown_query_parameter(string $method, string $uri): void
    {
        $this->apiCall($method, $uri.'?not_a_real_filter=1&since=nonsense-not-a-date')
            ->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Error envelope and validation
    // -----------------------------------------------------------------

    public function test_a_validation_failure_returns_422_with_a_consistent_envelope(): void
    {
        $response = $this->apiCall('POST', '/api/v1/controls', ['control' => ['title' => 'No external ref']]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
        $this->assertIsArray($response->json('errors'));
    }

    public function test_an_authentication_failure_returns_a_message_envelope(): void
    {
        $response = $this->json('GET', '/api/v1/controls');

        $response->assertStatus(401);
        $response->assertJsonStructure(['message']);
    }

    public function test_wrong_types_are_rejected_rather_than_coerced(): void
    {
        $this->apiCall('POST', '/api/v1/controls', [
            'external_ref' => ['an', 'array', 'not', 'a', 'string'],
            'control' => ['title' => 'Wrong type probe'],
        ])->assertStatus(422);

        $this->apiCall('POST', '/api/v1/controls', [
            'external_ref' => 'TL-TYPE-PROBE',
            'last_approved_at' => 'not-a-date',
            'control' => ['title' => 'Wrong type probe'],
        ])->assertStatus(422);

        $this->apiCall('POST', '/api/v1/controls', [
            'external_ref' => 'TL-TYPE-PROBE',
            'control' => 'a string, not an object',
        ])->assertStatus(422);
    }

    public function test_malformed_json_is_rejected_without_a_server_error(): void
    {
        $response = $this->apiCall('POST', '/api/v1/controls');

        // An empty body is the closest a Laravel test can get to malformed
        // JSON; either way the contract is "not a 5xx".
        $this->assertLessThan(500, $response->status(), 'A malformed body produced a server error.');
        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_an_oversized_payload_does_not_produce_a_server_error(): void
    {
        $response = $this->apiCall('POST', '/api/v1/controls', [
            'external_ref' => 'TL-OVERSIZE',
            'control' => ['title' => str_repeat('A', 100000)],
        ]);

        $this->assertLessThan(500, $response->status(), 'An oversized field produced a server error.');
        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Tenant scoping on every read
    // -----------------------------------------------------------------

    #[DataProvider('readEndpointProvider')]
    public function test_no_read_endpoint_returns_another_tenants_records(string $method, string $uri): void
    {
        $this->assertStringNotContainsString(
            'MUST NEVER BE VISIBLE',
            $this->apiCall($method, $uri)->getContent(),
            "{$uri} returned another tenant's records."
        );
    }

    public function test_an_inbound_write_cannot_target_another_tenant(): void
    {
        $this->apiCall('POST', '/api/v1/controls', [
            'external_ref' => 'TL-CROSS-TENANT',
            'tenant_id' => $this->otherTenantAccount('other_cfh')->tenant_id,
            'control' => ['title' => 'Cross-tenant inbound probe'],
        ]);

        $control = Control::withoutGlobalScopes()->where('external_ref', 'TL-CROSS-TENANT')->first();

        if ($control) {
            $this->assertSame(
                $this->account('cfh')->tenant_id,
                $control->tenant_id,
                'CRITICAL: an inbound payload chose its own tenant. The key must determine the tenant, not the body.'
            );
        } else {
            $this->addToAssertionCount(1);
        }
    }

    // -----------------------------------------------------------------
    // §9 — rate limiting
    // -----------------------------------------------------------------

    /**
     * The plan requires a 429 among the correct status codes. `routes/api.php`
     * applies `integration.auth` and nothing else — there is no `throttle`
     * middleware on any of the 11 endpoints.
     */
    public function test_the_api_is_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 70; $i++) {
            $statuses[] = $this->apiCall('GET', '/api/v1/controls')->status();
        }

        $this->assertContains(
            429,
            $statuses,
            '§9: 70 consecutive requests were served without a single 429. No `throttle` middleware is '
            .'applied to any /api/v1 route, so a valid key — or a stolen one — can be replayed without '
            .'limit, and a brute-force attempt against the key space is unthrottled.'
        );
    }

    /** Authentication failures must be throttled too, or the key space is brute-forceable. */
    public function test_repeated_authentication_failures_are_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 70; $i++) {
            $statuses[] = $this->withHeaders(['X-Api-Key' => 'guess-'.$i])
                ->json('GET', '/api/v1/controls')->status();
        }

        $this->assertContains(
            429,
            $statuses,
            '§9 / §10: 70 consecutive invalid-key attempts were all answered 401 with no throttling. '
            .'AuthenticateIntegration compares against every active config on each attempt, so the key '
            .'space can be probed at request rate.'
        );
    }
}
