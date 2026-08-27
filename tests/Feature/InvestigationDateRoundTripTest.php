<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §7.1 — a calendar date must survive the round trip unchanged.
 *
 * The reference implementation shows 28 Jul 2026 on the case header and
 * loads 27/07/2026 into the edit form. That is not a display quirk: a
 * `date` cast serialises through Carbon's `toJSON()`, which converts the
 * local midnight it holds into UTC. One hour west of Greenwich, midnight
 * on the 28th is 23:00 on the 27th, and the form binds the date half of
 * that string.
 *
 * This codebase was one config change away from the same bug — it is
 * masked only because `config('app.timezone')` is currently 'UTC'. The
 * tenant this platform is built for keeps Africa/Lagos time, so the test
 * runs under both: a reported date, saved and reloaded, must name the
 * same day no matter which timezone the application is configured for.
 */
class InvestigationDateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** The two timezones that matter: the deployment's, and the default. */
    private const TIMEZONES = ['Africa/Lagos', 'UTC'];

    private const REPORTED = '2026-07-28';

    private const TARGET = '2026-10-13';

    private Tenant $tenant;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->officer = User::factory()->create([
            'email' => 'officer@test.local',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
        $this->officer->assignRole('Control Officer');
    }

    /**
     * Deliberately not named with a `test` prefix — Pint's
     * php_unit_method_casing rule rewrites any such helper into snake_case
     * and PHPUnit then tries to run it.
     */
    private function useTimezone(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    private function open(): Investigation
    {
        $this->actingAs($this->officer);

        return app(InvestigationService::class)->open([
            'title' => 'Suspected teller cash suppression, Branch 042',
            'category' => 'fraud',
            'source' => 'management_directive',
            'priority' => 'High',
            'reported_date' => self::REPORTED,
            'target_completion_date' => self::TARGET,
        ], $this->officer);
    }

    public function test_the_edit_form_loads_the_date_that_was_saved(): void
    {
        foreach (self::TIMEZONES as $timezone) {
            $this->useTimezone($timezone);

            $investigation = $this->open();

            $this->actingAs($this->officer)
                ->get(route('investigations.edit', $investigation->id))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('investigation.reported_date', self::REPORTED)
                    ->where('investigation.target_completion_date', self::TARGET));
        }
    }

    public function test_saving_the_edit_form_unchanged_does_not_move_the_date(): void
    {
        foreach (self::TIMEZONES as $timezone) {
            $this->useTimezone($timezone);

            $investigation = $this->open();

            // Exactly what the form posts back when nothing was touched.
            $this->actingAs($this->officer)
                ->put(route('investigations.update', $investigation->id), [
                    'title' => $investigation->title,
                    'category' => $investigation->category,
                    'source' => $investigation->source,
                    'priority' => $investigation->priority,
                    'reported_date' => self::REPORTED,
                    'target_completion_date' => self::TARGET,
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('investigations', [
                'id' => $investigation->id,
                'reported_date' => self::REPORTED,
                'target_completion_date' => self::TARGET,
            ]);

            $this->actingAs($this->officer)
                ->get(route('investigations.edit', $investigation->id))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('investigation.reported_date', self::REPORTED)
                    ->where('investigation.target_completion_date', self::TARGET));
        }
    }

    /**
     * The narrower unit fact behind both tests above, asserted directly on
     * the serialised payload so a future cast change cannot quietly
     * reintroduce an instant where a calendar date belongs.
     */
    public function test_a_date_column_serialises_as_a_calendar_date(): void
    {
        foreach (self::TIMEZONES as $timezone) {
            $this->useTimezone($timezone);

            $payload = (new Investigation(['reported_date' => self::REPORTED]))->toArray();

            $this->assertSame(
                self::REPORTED,
                $payload['reported_date'],
                "reported_date must serialise as {$timezone}-independent Y-m-d.",
            );
        }
    }
}
