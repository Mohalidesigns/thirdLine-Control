<?php

namespace Tests\e2e;

use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Risk;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part B §12 — the form submission matrix.
 *
 * Applied at full depth to the priority forms in the system map: control
 * definition, exception raise, and risk register. The security checks
 * (§12.B server-parity, §12.C mass assignment / XSS / SQLi / CSRF) are
 * swept across every form that accepts them rather than sampled, because
 * those are the checks where a single unguarded field is a Critical.
 *
 * Note on §12.C.16 (CSRF): this suite runs with APP_ENV=testing, which is
 * exactly what disables Laravel's CSRF verification, so a replay test here
 * would be vacuous. The configuration is asserted instead — see
 * test_csrf_protection_is_enabled_with_only_the_two_documented_exemptions.
 */
class FormMatrixTest extends E2ETestCase
{
    private function controlPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'E2E form matrix control',
            'description' => 'Synthetic.',
            'objective' => 'Synthetic.',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
        ], $overrides);
    }

    private function exceptionPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'E2E form matrix exception',
            'description' => 'Synthetic.',
            'severity' => 'Medium',
            'target_closure_date' => now()->addDays(30)->toDateString(),
        ], $overrides);
    }

    // =================================================================
    // §12.C.17 — mass assignment
    // =================================================================

    /**
     * The highest-value check in the matrix. Each payload adds fields the
     * form does not own — a foreign tenant, a privileged status, an
     * identity — and asserts none of them reach the database.
     */
    public function test_12_c_17_control_creation_ignores_injected_privileged_fields(): void
    {
        $foreignTenant = $this->otherTenantAccount('other_cfh')->tenant_id;

        $this->actingAs($this->account('officer'))->post('/controls', $this->controlPayload([
            'title' => 'E2E mass assignment probe (control)',
            'tenant_id' => $foreignTenant,
            'status' => 'Active',
            'approved_by' => $this->account('officer')->id,
            'approved_at' => now()->toDateTimeString(),
            'current_version' => 99,
            'sync_status' => 'synced_from_thirdline',
            'is_template' => true,
        ]))->assertSessionHasNoErrors();

        $control = Control::withoutGlobalScopes()->where('title', 'E2E mass assignment probe (control)')->first();

        $this->assertNotNull($control, 'Fixture: the control was not created.');

        $this->assertNotSame($foreignTenant, $control->tenant_id, 'CRITICAL: tenant_id was mass-assignable — a record was planted in another tenant.');
        $this->assertSame($this->account('officer')->tenant_id, $control->tenant_id);
        $this->assertSame('Draft', $control->status, 'CRITICAL: status was mass-assignable — the maker-checker approval chain (FR-1.8) can be skipped.');
        $this->assertNull($control->approved_by, 'approved_by was mass-assignable.');
        $this->assertNull($control->approved_at, 'approved_at was mass-assignable.');
        $this->assertFalse((bool) $control->is_template, 'is_template was mass-assignable.');
    }

    public function test_12_c_17_exception_creation_ignores_injected_closure_fields(): void
    {
        $this->actingAs($this->account('officer'))->post('/exceptions', $this->exceptionPayload([
            'title' => 'E2E mass assignment probe (exception)',
            'status' => 'Verified-Closed',
            'verified_by' => $this->account('officer')->id,
            'verified_at' => now()->toDateTimeString(),
            'verification_method' => 'Self-verified by mass assignment.',
            'tenant_id' => $this->otherTenantAccount('other_cfh')->tenant_id,
            'is_overdue' => false,
            'age_days' => 0,
        ]))->assertSessionHasNoErrors();

        $exception = ControlException::withoutGlobalScopes()
            ->where('title', 'E2E mass assignment probe (exception)')->first();

        $this->assertNotNull($exception, 'Fixture: the exception was not created.');

        $this->assertNotSame(
            'Verified-Closed',
            $exception->status,
            'CRITICAL: an exception was created already closed by mass assignment, bypassing FR-5.4 entirely.'
        );
        $this->assertContains($exception->status, ['Open', 'Assigned']);
        $this->assertNull($exception->verified_by, 'CRITICAL: verified_by was mass-assignable.');
        $this->assertNull($exception->verified_at, 'CRITICAL: verified_at was mass-assignable.');
        $this->assertNull($exception->verification_method, 'CRITICAL: verification_method was mass-assignable.');
        $this->assertSame($this->account('officer')->tenant_id, $exception->tenant_id);
    }

    public function test_12_c_17_a_record_cannot_be_created_with_a_chosen_id(): void
    {
        $this->actingAs($this->account('officer'))->post('/controls', $this->controlPayload([
            'title' => 'E2E id injection probe',
            'id' => 999999,
        ]))->assertSessionHasNoErrors();

        $this->assertNull(
            Control::withoutGlobalScopes()->find(999999),
            'The primary key was mass-assignable.'
        );
    }

    // =================================================================
    // §12.A.9 / §12.B.13 — server-side validation, bypassing the UI
    // =================================================================

    public static function injectedEnumProvider(): array
    {
        return [
            'control type not in list' => ['/controls', ['type' => 'Catastrophic'], 'type'],
            'control nature not in list' => ['/controls', ['nature' => 'Telepathic'], 'nature'],
            'control frequency not in list' => ['/controls', ['frequency' => 'Hourly'], 'frequency'],
        ];
    }

    #[DataProvider('injectedEnumProvider')]
    public function test_12_b_13_a_dropdown_value_not_in_the_list_is_rejected_server_side(string $uri, array $bad, string $field): void
    {
        $before = Control::withoutGlobalScopes()->count();

        $this->actingAs($this->account('officer'))
            ->post($uri, $this->controlPayload($bad))
            ->assertSessionHasErrors($field);

        $this->assertSame($before, Control::withoutGlobalScopes()->count(), 'An invalid enum value created a record.');
    }

    public function test_12_b_13_an_invalid_severity_is_rejected_server_side(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/exceptions', $this->exceptionPayload(['severity' => 'Apocalyptic']))
            ->assertSessionHasErrors('severity');
    }

    public function test_12_a_1_an_empty_submission_flags_every_required_field(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/controls', [])
            ->assertSessionHasErrors(['title', 'type', 'nature', 'frequency']);
    }

    public function test_12_a_3_whitespace_only_is_treated_as_empty(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/controls', $this->controlPayload(['title' => '     ']))
            ->assertSessionHasErrors('title');
    }

    public function test_12_a_4_over_length_input_is_rejected_server_side(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/controls', $this->controlPayload(['title' => str_repeat('A', 256)]))
            ->assertSessionHasErrors('title');
    }

    public function test_12_a_7_a_target_closure_date_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/exceptions', $this->exceptionPayload(['target_closure_date' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors('target_closure_date');
    }

    public function test_12_a_7_an_impossible_date_is_rejected(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/exceptions', $this->exceptionPayload(['target_closure_date' => '2026-02-31']))
            ->assertSessionHasErrors('target_closure_date');
    }

    public function test_12_a_6_a_numeric_field_rejects_out_of_range_and_non_numeric_values(): void
    {
        foreach ([['inherent_likelihood' => 0], ['inherent_likelihood' => 999], ['inherent_impact' => 'four'], ['inherent_impact' => -3]] as $bad) {
            $this->actingAs($this->account('officer'))
                ->post('/risks', array_merge([
                    'title' => 'E2E numeric probe',
                    'inherent_likelihood' => 3,
                    'inherent_impact' => 3,
                ], $bad))
                ->assertSessionHasErrors(array_key_first($bad));
        }
    }

    // =================================================================
    // §12.A.11 — Nigerian names, apostrophes, accents, unicode
    // =================================================================

    public static function unicodeProvider(): array
    {
        return [
            'apostrophe' => ["O'Brien reconciliation control"],
            'hyphenated' => ['Adeyemi-Okonkwo dual authorisation'],
            'accents' => ['Contrôle des rapprochements bancaires'],
            'yoruba diacritics' => ['Ìdánilójú owó ìdókòwò'],
            'emoji' => ['Cash count control 🏦'],
            'ampersand' => ['Cut-off & accruals review'],
        ];
    }

    #[DataProvider('unicodeProvider')]
    public function test_12_a_11_special_characters_are_stored_and_returned_intact(string $title): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/controls', $this->controlPayload(['title' => $title]))
            ->assertSessionHasNoErrors();

        $control = Control::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame($title, $control->title, 'The stored value differs from what was submitted.');

        // §12.E.30 — and it renders back unchanged.
        $response = $this->actingAs($this->account('officer'))->get('/controls/'.$control->id);
        $response->assertOk();
        $this->assertSame($title, data_get($response->viewData('page')['props'], 'control.title'));
    }

    // =================================================================
    // §12.C.14 — XSS
    // =================================================================

    public static function xssProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'img onerror' => ['"><img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg/onload=alert(1)>'],
            'javascript uri' => ['<a href="javascript:alert(1)">click</a>'],
        ];
    }

    /**
     * Inertia serialises page props into the *content* of a
     * `<script id="app" type="application/json">` element. Inside that
     * element the HTML parser recognises exactly one thing — a literal
     * `</script` sequence — so `<script>`, `<img onerror=…>` and
     * `<svg onload=…>` are inert text. The defence that matters is
     * therefore that no raw `</script` can reach the block, which
     * json_encode's slash escaping (`<\/script>`) provides.
     *
     * That is what this asserts. Three broader assertions were tried
     * first and every one of them was wrong: "onerror=alert(1)" survives
     * correct escaping untouched (escaping rewrites < > " & and nothing
     * else); a bare "<script" matches the page's own Vite asset tags; and
     * a whole-payload match hits the JSON-escaped form, because
     * `\"><img src=x onerror=alert(1)>` contains the payload as a
     * substring. Each would have reported a false Critical.
     */
    #[DataProvider('xssProvider')]
    public function test_12_c_14_a_stored_payload_cannot_break_out_of_the_inertia_page_block(string $payload): void
    {
        $title = 'XSS probe '.$payload;

        $this->actingAs($this->account('officer'))
            ->post('/controls', $this->controlPayload([
                'title' => $title,
                // Deliberately includes a closing tag: this is the only
                // sequence that can terminate the block early.
                'description' => $payload.'</script><script>alert(document.domain)</script>',
            ]))
            ->assertSessionHasNoErrors();

        $control = Control::withoutGlobalScopes()->latest('id')->first();

        // Stored verbatim is correct — encoding belongs at render time.
        $this->assertSame($title, $control->title, 'Input was mangled at rest rather than encoded on output.');

        $response = $this->actingAs($this->account('officer'))->get('/controls/'.$control->id);
        $response->assertOk();

        $block = $this->inertiaPageBlock($response->getContent());

        $this->assertNotNull($block, 'Could not locate the Inertia page block; the assertion would be vacuous.');

        $this->assertStringContainsString(
            'XSS probe',
            $block,
            'The payload never reached the page block, so this test proves nothing.'
        );

        $this->assertStringNotContainsString(
            '</script',
            $block,
            'CRITICAL: a stored value placed a raw closing-script sequence inside the Inertia page '
            .'block, terminating it early and allowing arbitrary markup to follow — stored XSS on the '
            .'application origin.'
        );
    }

    /** Content of the `<script id="app" type="application/json">` element. */
    private function inertiaPageBlock(string $html): ?string
    {
        if (! preg_match('#<script[^>]*type="application/json"[^>]*>(.*?)</script>#s', $html, $m)) {
            return null;
        }

        return $m[1];
    }

    public function test_12_c_14_an_xss_payload_survives_into_an_export_without_executing(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/controls', $this->controlPayload(['title' => '<script>alert("export")</script>']))
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->account('cfh'))->get('/reports/controls.xlsx');
        $response->assertOk();

        // The spreadsheet is a zip; the point is that generation does not
        // break and no HTML is interpreted anywhere on the way.
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    // =================================================================
    // §12.C.15 — SQL injection
    // =================================================================

    public static function sqlInjectionProvider(): array
    {
        return [
            'classic or' => ["' OR 1=1--"],
            'union select' => ["' UNION SELECT null,null,null--"],
            'drop table' => ["'; DROP TABLE control_exceptions;--"],
            'boolean blind' => ["' AND SLEEP(0)--"],
        ];
    }

    #[DataProvider('sqlInjectionProvider')]
    public function test_12_c_15_injection_strings_in_a_search_filter_are_parameterised(string $payload): void
    {
        $baseline = ControlException::withoutGlobalScopes()->count();

        $response = $this->actingAs($this->account('officer'))
            ->get('/exceptions?search='.urlencode($payload));

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame(
            0,
            (int) data_get($props, 'exceptions.total'),
            'An injection string matched rows — the filter is not parameterised.'
        );

        $this->assertSame(
            $baseline,
            ControlException::withoutGlobalScopes()->count(),
            'The register changed size after an injection attempt.'
        );

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent(), 'A database error leaked to the client.');
    }

    // =================================================================
    // §12.D.19 / §12.D.20 — double submission
    // =================================================================

    public function test_12_d_19_a_double_submitted_form_creates_two_records(): void
    {
        $payload = $this->exceptionPayload(['title' => 'E2E double submit probe']);

        $this->actingAs($this->account('officer'))->post('/exceptions', $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->account('officer'))->post('/exceptions', $payload)->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            ControlException::withoutGlobalScopes()->where('title', 'E2E double submit probe')->count(),
            '§12.D.19: an identical resubmission created a second exception. A double-clicked submit, a '
            .'browser-back-and-resubmit, or a retried request after a timeout each produce a duplicate '
            .'record in the register — with its own reference, its own escalation ladder and its own '
            .'closure requirement.'
        );
    }

    // =================================================================
    // §12.C.16 — CSRF configuration
    // =================================================================

    /**
     * A replay test cannot run inside this suite: APP_ENV=testing is
     * precisely what disables Laravel's CSRF verification, so any
     * assertion here would be vacuous.
     *
     * Enforcement was therefore verified against the **live** e2e
     * application, which runs APP_ENV=e2e — see
     * `docs/testing/evidence/TC-12C16-csrf-live-verification.txt`:
     * POST /controls, /exceptions and /login each returned **419** with no
     * token, and the two CSRF-exempt webhook routes returned **403** from
     * their own signature checks.
     *
     * What this test adds is a guard on the exemption list, so that a new
     * exemption fails the build rather than passing unnoticed.
     */
    public function test_csrf_exemptions_are_limited_to_the_two_documented_routes(): void
    {
        $property = (new \ReflectionClass(ValidateCsrfToken::class))
            ->getProperty('neverVerify');
        $property->setAccessible(true);

        $exemptions = $property->getValue(
            app(ValidateCsrfToken::class)
        );

        sort($exemptions);

        $this->assertSame(
            ['auth/sso/*/acs', 'webhooks/*'],
            $exemptions,
            'The CSRF exemption list has changed. Every exemption must carry its own proof of origin — '
            .'a SAML signature or a webhook HMAC — and both of those claims are themselves still '
            .'untested (TC-New-01, TC-New-02).'
        );
    }

    // =================================================================
    // §12.E.29 — what was submitted is what is stored
    // =================================================================

    public function test_12_e_29_every_submitted_value_persists_exactly(): void
    {
        $payload = $this->controlPayload([
            'title' => 'E2E persistence probe',
            'description' => "Line one.\nLine two with a trailing space. ",
            'objective' => 'Ensure §12.E.29 holds.',
            'type' => 'Detective',
            'nature' => 'IT-Dependent Manual',
            'frequency' => 'Semi-annual',
            'is_key_control' => true,
            'department' => 'Operations',
        ]);

        $this->actingAs($this->account('officer'))->post('/controls', $payload)->assertSessionHasNoErrors();

        $row = DB::table('controls')->where('title', 'E2E persistence probe')->first();

        $this->assertNotNull($row);
        $this->assertSame('Detective', $row->type);
        $this->assertSame('IT-Dependent Manual', $row->nature);
        $this->assertSame('Semi-annual', $row->frequency);
        $this->assertSame(1, (int) $row->is_key_control);
        $this->assertSame('Operations', $row->department);
        $this->assertSame($payload['objective'], $row->objective);
        $this->assertStringContainsString('Line two', $row->description, 'Multi-line text was truncated.');
    }

    // =================================================================
    // §12.E.31 — related records
    // =================================================================

    public function test_12_e_31_creating_a_record_writes_its_audit_entry(): void
    {
        $this->actingAs($this->account('officer'))
            ->post('/controls', $this->controlPayload(['title' => 'E2E audit-on-create probe']))
            ->assertSessionHasNoErrors();

        $control = Control::withoutGlobalScopes()->where('title', 'E2E audit-on-create probe')->firstOrFail();

        $this->assertNotNull(
            AuditTrail::where('entity_type', Control::class)
                ->where('entity_id', $control->id)->where('action', 'created')->first(),
            '§12.E.31: creating a control must write an audit record.'
        );
    }
}
