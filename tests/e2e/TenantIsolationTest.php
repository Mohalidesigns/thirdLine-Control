<?php

namespace Tests\e2e;

use App\Models\Control;
use App\Models\ControlException;
use Database\Seeders\E2ETestSeeder;

/**
 * TC-02-07 (IDOR) and TC-New-04 (tenant isolation).
 *
 * The fixtures are two tenants whose records are deliberately named
 * "MUST NEVER BE VISIBLE TO TENANT 1", so a leak is unmistakable in the
 * failure message rather than an id comparison the reader has to decode.
 *
 * Isolation is tested in both directions. Testing only one direction
 * proves the scope filters *a* tenant, not that it filters correctly.
 */
class TenantIsolationTest extends E2ETestCase
{
    // -----------------------------------------------------------------
    // TC-02-07 — direct object reference by URL
    // -----------------------------------------------------------------

    public function test_tc_02_07_tenant_one_cannot_open_another_tenants_exception(): void
    {
        $foreign = $this->otherTenantException();

        foreach (['admin', 'cfh', 'officer', 'owner', 'manager', 'exec'] as $account) {
            $response = $this->actingAs($this->account($account))
                ->get('/exceptions/'.$foreign->id);

            $this->assertFalse(
                $response->isSuccessful(),
                "TC-02-07 CRITICAL: {$account} opened another tenant's exception (id {$foreign->id})."
            );

            $this->assertStringNotContainsString(
                'MUST NEVER BE VISIBLE',
                $response->getContent() ?: '',
                "TC-02-07 CRITICAL: another tenant's exception leaked into the response for {$account}."
            );
        }
    }

    public function test_tc_02_07_tenant_one_cannot_open_another_tenants_control(): void
    {
        $foreign = $this->otherTenantControl();

        foreach (['admin', 'cfh', 'officer', 'owner'] as $account) {
            $response = $this->actingAs($this->account($account))
                ->get('/controls/'.$foreign->id);

            $this->assertFalse(
                $response->isSuccessful(),
                "TC-02-07 CRITICAL: {$account} opened another tenant's control (id {$foreign->id})."
            );
        }
    }

    /** The reverse direction — isolation must not be one-way. */
    public function test_tc_02_07_the_other_tenant_cannot_reach_ours(): void
    {
        $ours = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->firstOrFail();

        $response = $this->actingAs($this->otherTenantAccount('other_cfh'))
            ->get('/exceptions/'.$ours->id);

        $this->assertFalse(
            $response->isSuccessful(),
            "TC-02-07 CRITICAL: the other tenant's Control Function Head opened our exception {$ours->reference}."
        );
    }

    // -----------------------------------------------------------------
    // State-changing IDOR — the more serious half
    // -----------------------------------------------------------------

    public function test_tc_02_07_cannot_close_another_tenants_exception(): void
    {
        $foreign = $this->otherTenantException();

        $this->assertSame('Remediated', $foreign->status, 'Fixture assumption: the foreign exception is closable.');

        $this->actingAs($this->account('cfh'))
            ->post('/exceptions/'.$foreign->id.'/close', [
                'verification_method' => 'Cross-tenant closure attempt.',
                'closure_notes' => 'Should never succeed.',
            ]);

        $this->assertSame(
            'Remediated',
            ControlException::withoutGlobalScopes()->findOrFail($foreign->id)->status,
            "TC-02-07 CRITICAL: our Control Function Head closed another tenant's exception."
        );
    }

    public function test_tc_02_07_cannot_mutate_another_tenants_control(): void
    {
        $foreign = $this->otherTenantControl();
        $before = $foreign->title;

        $this->actingAs($this->account('cfh'))
            ->put('/controls/'.$foreign->id, [
                'title' => 'HIJACKED BY TENANT 1',
                'description' => $foreign->description,
                'objective' => $foreign->objective,
                'type' => 'Preventive',
                'nature' => 'Manual',
                'frequency' => 'Monthly',
                'change_reason' => 'Cross-tenant mutation attempt.',
            ]);

        $this->assertSame(
            $before,
            Control::withoutGlobalScopes()->findOrFail($foreign->id)->title,
            "TC-02-07 CRITICAL: our Control Function Head renamed another tenant's control."
        );
    }

    // -----------------------------------------------------------------
    // Listings must not leak
    // -----------------------------------------------------------------

    public function test_no_index_listing_leaks_another_tenants_records(): void
    {
        $leaks = [];

        $pages = [
            '/exceptions' => 'exceptions',
            '/controls' => 'controls',
            '/risks' => 'risks',
            '/dashboard' => 'dashboard',
        ];

        foreach (['admin', 'cfh', 'officer'] as $account) {
            foreach ($pages as $uri => $label) {
                $response = $this->actingAs($this->account($account))->get($uri);

                if (! $response->isSuccessful()) {
                    continue;
                }

                if (str_contains($response->getContent() ?: '', 'MUST NEVER BE VISIBLE')) {
                    $leaks[] = "{$account} → {$label}";
                }
            }
        }

        $this->assertSame(
            [],
            $leaks,
            "TC-New-04 CRITICAL: another tenant's records appeared in listings:\n  ".implode("\n  ", $leaks)
        );
    }

    // -----------------------------------------------------------------
    // Payload IDOR — a foreign id smuggled into a body, not a URL
    // -----------------------------------------------------------------

    public function test_cannot_assign_our_exception_to_a_user_from_another_tenant(): void
    {
        $ours = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->firstOrFail();

        $foreignUser = $this->otherTenantAccount('other_owner');

        $this->actingAs($this->account('officer'))
            ->post('/exceptions/'.$ours->id.'/assign', ['user_id' => $foreignUser->id]);

        $this->assertNotSame(
            $foreignUser->id,
            ControlException::withoutGlobalScopes()->findOrFail($ours->id)->responsible_party_id,
            'A user from another tenant was made responsible for our exception. '
            .'`exists:users,id` validates existence but not tenancy.'
        );
    }

    /**
     * Establishes how far the payload-IDOR above actually reaches.
     *
     * `ExceptionPolicy::view()` grants sight to the responsible party. If
     * the tenant scope did not also hold, assigning a foreign user would
     * hand them our exception outright — the difference between a
     * validation gap and a cross-tenant breach. This test pins down which
     * one it is, so the defect can be rated on evidence.
     */
    public function test_a_cross_tenant_assignment_still_does_not_grant_read_access(): void
    {
        $ours = ControlException::withoutGlobalScopes()
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->firstOrFail();

        $foreignUser = $this->otherTenantAccount('other_owner');

        // Force the state the payload IDOR produces.
        $ours->forceFill(['responsible_party_id' => $foreignUser->id])->saveQuietly();

        $response = $this->actingAs($foreignUser)->get('/exceptions/'.$ours->id);

        $this->assertFalse(
            $response->isSuccessful(),
            'CRITICAL: a cross-tenant assignment granted the foreign user read access to our exception. '
            .'The BelongsToTenant global scope is the only thing standing between the payload-IDOR gap '
            .'and a genuine cross-tenant breach.'
        );
    }

    // -----------------------------------------------------------------
    // The integration surface — a tenant's API key must scope its reads
    // -----------------------------------------------------------------

    public function test_api_key_does_not_expose_another_tenants_records(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => E2ETestSeeder::THIRDLINE_API_KEY])
            ->getJson('/api/v1/exceptions');

        $response->assertOk();

        $this->assertStringNotContainsString(
            'MUST NEVER BE VISIBLE',
            $response->getContent(),
            "TC-02-07 CRITICAL: /api/v1/exceptions returned another tenant's records. "
            .'The key is scoped to one tenant, so the response must be too.'
        );

        $controls = $this->withHeaders(['X-Api-Key' => E2ETestSeeder::THIRDLINE_API_KEY])
            ->getJson('/api/v1/controls');

        $controls->assertOk();

        $this->assertStringNotContainsString(
            'MUST NEVER BE VISIBLE',
            $controls->getContent(),
            "TC-02-07 CRITICAL: /api/v1/controls returned another tenant's records."
        );
    }

    public function test_api_rejects_a_missing_or_wrong_key(): void
    {
        $this->getJson('/api/v1/controls')->assertStatus(401);
        $this->withHeaders(['X-Api-Key' => 'not-a-real-key'])->getJson('/api/v1/controls')->assertStatus(401);
    }
}
