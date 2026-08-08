<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\RedactionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PII redaction (Part C §C.5, R5).
 *
 * The outbound-payload assertion lives in AiGatewayTest, which mocks the
 * HTTP client and inspects the body. This file tests the redactor itself:
 * every configured pattern, the Luhn discrimination that stops it eating
 * ordinary reference numbers, and the stability that lets a model reason
 * about "the same person" without ever being told who.
 */
class AiRedactionTest extends TestCase
{
    use RefreshDatabase;

    private RedactionService $redaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        // The name pass is driven by the tenant's own user table.
        User::factory()->create(['name' => 'Adaeze Okonkwo', 'email' => 'adaeze@bank.test', 'tenant_id' => $tenant->id]);
        User::factory()->create(['name' => 'Tunde Bakare', 'email' => 'tunde@bank.test', 'tenant_id' => $tenant->id]);

        $this->redaction = app(RedactionService::class);
    }

    public function test_it_redacts_every_configured_pattern(): void
    {
        $text = implode("\n", [
            'Contact the customer on funmi.adeleke@example.com or 08031234567.',
            'International line +2348031234567 also rings.',
            'The NUBAN is 0123456789 and the BVN is 22334455667.',
            'Card used was 4111111111111111.',
        ]);

        $redacted = $this->redaction->redact($text);

        $this->assertStringNotContainsString('funmi.adeleke@example.com', $redacted);
        $this->assertStringNotContainsString('08031234567', $redacted);
        $this->assertStringNotContainsString('+2348031234567', $redacted);
        $this->assertStringNotContainsString('0123456789', $redacted);
        $this->assertStringNotContainsString('22334455667', $redacted);
        $this->assertStringNotContainsString('4111111111111111', $redacted);

        $this->assertStringContainsString('[EMAIL]', $redacted);
        $this->assertStringContainsString('[PHONE]', $redacted);
        $this->assertStringContainsString('[ACCOUNT]', $redacted);
        $this->assertStringContainsString('[BVN]', $redacted);
        $this->assertStringContainsString('[PAN]', $redacted);
    }

    public function test_it_redacts_names_from_the_tenant_user_table(): void
    {
        $redacted = $this->redaction->redact('Adaeze Okonkwo approved the posting raised by Tunde Bakare.');

        $this->assertStringNotContainsString('Adaeze', $redacted);
        $this->assertStringNotContainsString('Bakare', $redacted);
        $this->assertMatchesRegularExpression('/\[PERSON_\d\]/', $redacted);
    }

    /**
     * The model must still be able to reason about relationships. If both
     * names collapsed to one token, "X approved X's posting" would read as a
     * self-approval — a segregation-of-duties finding the redactor invented.
     */
    public function test_person_placeholders_are_distinct_and_stable_within_a_request(): void
    {
        $redacted = $this->redaction->redact(
            'Adaeze Okonkwo raised it. Tunde Bakare approved it. Adaeze Okonkwo then closed it.',
        );

        preg_match_all('/\[PERSON_(\d)\]/', $redacted, $matches);

        $this->assertCount(3, $matches[0], 'Every name occurrence should be replaced.');
        $this->assertCount(2, array_unique($matches[1]), 'Two distinct people should get two distinct tokens.');
        $this->assertSame($matches[0][0], $matches[0][2], 'The same person should get the same token throughout.');
    }

    /**
     * The PAN pattern deliberately matches any 13-19 digit run, so a Luhn
     * check decides. Without it, every long reference number in the estate
     * would be redacted into uselessness.
     */
    public function test_a_luhn_invalid_long_number_is_not_treated_as_a_card(): void
    {
        $notACard = '1234567890123456';
        $this->assertStringContainsString($notACard, $this->redaction->redact("Reference {$notACard} applies."));

        $isACard = '4111111111111111';
        $this->assertStringNotContainsString($isACard, $this->redaction->redact("Card {$isACard} was used."));
    }

    public function test_it_redacts_excluded_attributes_by_key_whatever_they_contain(): void
    {
        $redacted = $this->redaction->redactArray([
            'title' => 'Mandate irregularity',
            'customer_name' => 'Chidinma Eze',
            'customer_address' => '14 Bourdillon Road, Ikoyi',
            'bvn' => 'not-even-a-number',
            'nested' => ['account_number' => '0123456789', 'note' => 'Escalated.'],
        ]);

        $this->assertSame('Mandate irregularity', $redacted['title']);
        $this->assertSame('[REDACTED]', $redacted['customer_name']);
        $this->assertSame('[REDACTED]', $redacted['customer_address']);
        $this->assertSame('[REDACTED]', $redacted['bvn']);
        $this->assertSame('[REDACTED]', $redacted['nested']['account_number']);
        $this->assertSame('Escalated.', $redacted['nested']['note']);
    }

    public function test_contains_sensitive_is_the_egress_backstop(): void
    {
        $this->assertTrue($this->redaction->containsSensitive('Reach them on 08031234567.'));
        $this->assertFalse($this->redaction->containsSensitive('Reach them on [PHONE].'));
    }

    /**
     * Names come back for display so a reviewer reads a sentence. Account
     * numbers do not — rehydrating those would put them back on a screen and
     * into a PDF export, which is exactly what R5 exists to prevent.
     */
    public function test_only_person_placeholders_rehydrate(): void
    {
        $redacted = $this->redaction->redact('Adaeze Okonkwo used account 0123456789.');
        $rehydrated = $this->redaction->rehydrate($redacted);

        $this->assertStringContainsString('Adaeze Okonkwo', $rehydrated);
        $this->assertStringNotContainsString('0123456789', $rehydrated);
        $this->assertStringContainsString('[ACCOUNT]', $rehydrated);
    }

    public function test_reset_clears_the_map_between_capability_runs(): void
    {
        $this->redaction->redact('Adaeze Okonkwo');
        $this->assertNotEmpty($this->redaction->map());

        $this->redaction->reset();
        $this->assertEmpty($this->redaction->map());
    }

    public function test_amount_redaction_is_off_by_default_and_works_when_enabled(): void
    {
        $text = 'A loss of ₦4,500,000.00 was recorded.';

        $this->assertStringContainsString('₦4,500,000.00', $this->redaction->redact($text));

        config(['ai.redaction.redact_amounts' => true]);
        $this->redaction->reset();

        $this->assertStringContainsString('[AMOUNT]', $this->redaction->redact($text));
    }
}
