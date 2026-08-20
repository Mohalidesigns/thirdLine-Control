<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RichText;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editor.js storage: documents live in `{field}_rich`, the original plain
 * column is derived on save (so search/exports keep working), unknown block
 * types are rejected, and inline HTML is sanitised on write.
 */
class RichTextTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->officer = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $this->officer->assignRole('Control Officer');
    }

    private function doc(array $blocks): array
    {
        return ['time' => 1, 'version' => '2.31.0', 'blocks' => $blocks];
    }

    private function controlPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Maker-checker on payments',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Daily',
        ], $overrides);
    }

    public function test_a_rich_description_is_stored_and_mirrored_to_plain_text(): void
    {
        $this->actingAs($this->officer)->post(route('controls.store'), $this->controlPayload([
            'description' => 'ignored — the mirror is derived server-side',
            'description_rich' => $this->doc([
                ['type' => 'header', 'data' => ['text' => 'Purpose', 'level' => 3]],
                ['type' => 'paragraph', 'data' => ['text' => 'No <b>single officer</b> may act alone.']],
                ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Initiate', 'Approve']]],
            ]),
        ]))->assertRedirect();

        $control = Control::latest('id')->firstOrFail();
        $this->assertSame("Purpose\nNo single officer may act alone.\n- Initiate\n- Approve", $control->description);
        $this->assertSame('Purpose', $control->description_rich['blocks'][0]['data']['text']);
    }

    public function test_an_unknown_block_type_is_rejected_not_stored(): void
    {
        $this->actingAs($this->officer)->post(route('controls.store'), $this->controlPayload([
            'description_rich' => $this->doc([
                ['type' => 'iframe', 'data' => ['src' => 'https://evil.example']],
            ]),
        ]))->assertSessionHasErrors('description_rich');

        $this->assertSame(0, Control::count());
    }

    public function test_inline_html_is_sanitised_on_write(): void
    {
        $dirty = $this->doc([
            ['type' => 'paragraph', 'data' => [
                'text' => 'Safe <b onclick="x()">bold</b> <script>alert(1)</script> <a href="javascript:alert(1)">bad link</a> <a href="https://ok.example">good</a>',
            ]],
        ]);

        $clean = RichText::sanitize($dirty);
        $text = $clean['blocks'][0]['data']['text'];

        $this->assertStringNotContainsString('script', $text);
        $this->assertStringNotContainsString('onclick', $text);
        $this->assertStringNotContainsString('javascript:', $text);
        $this->assertStringContainsString('<b>bold</b>', $text);
        $this->assertStringContainsString('href="https://ok.example"', $text);
    }

    public function test_legacy_plain_text_round_trips_through_the_migration_helper(): void
    {
        $doc = RichText::fromPlain("First line\n\nSecond <line>");

        $this->assertCount(2, $doc['blocks']);
        $this->assertSame('Second &lt;line&gt;', $doc['blocks'][1]['data']['text']);
        $this->assertSame("First line\nSecond <line>", RichText::toPlain($doc));
    }

    public function test_a_plain_only_submission_keeps_working(): void
    {
        $this->actingAs($this->officer)->post(route('controls.store'), $this->controlPayload([
            'description' => "Plain description\nsecond line",
        ]))->assertRedirect();

        $control = Control::latest('id')->firstOrFail();
        $this->assertSame("Plain description\nsecond line", $control->description);
        $this->assertNull($control->description_rich);
    }
}
