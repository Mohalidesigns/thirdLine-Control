<?php

namespace Database\Seeders;

use App\Models\ControlFrequency;
use App\Models\FrequencyAlias;
use App\Services\FrequencyResolver;
use Illuminate\Database\Seeder;

/**
 * CR-03 §C.1: the frequency catalogue and the client's own vocabulary,
 * as data. Every raw string the workbook uses is an alias row here, so a
 * revised workbook that spells "Quaterly" a fourth way is a row, not a
 * deploy.
 *
 * Platform rows (tenant_id NULL) — every tenant inherits them, and any
 * tenant can shadow one with a row of the same code.
 *
 * Idempotent: updateOrCreate on the natural unique keys.
 */
class ControlFrequencySeeder extends Seeder
{
    /**
     * Grace days are §G.5 of the change request and await client
     * confirmation; the Monthly figure (5) matches the due_date rule the
     * platform has used since Phase 3, so nothing shifts under existing
     * monthly controls.
     */
    private const FREQUENCIES = [
        ['code' => 'daily', 'label' => 'Daily', 'cycle' => 'daily', 'generation_mode' => 'scheduled',
            'grace_days' => 1, 'legacy_frequency' => 'Daily', 'sequence' => 10],
        ['code' => 'weekly', 'label' => 'Weekly', 'cycle' => 'weekly', 'generation_mode' => 'scheduled',
            'grace_days' => 2, 'legacy_frequency' => 'Weekly', 'sequence' => 20],
        ['code' => 'monthly', 'label' => 'Monthly', 'cycle' => 'monthly', 'generation_mode' => 'scheduled',
            'grace_days' => 5, 'legacy_frequency' => 'Monthly', 'sequence' => 30],
        ['code' => 'quarterly', 'label' => 'Quarterly', 'cycle' => 'quarterly', 'generation_mode' => 'scheduled',
            'grace_days' => 10, 'legacy_frequency' => 'Quarterly', 'sequence' => 40],
        ['code' => 'semiannual', 'label' => 'Semi-annual', 'cycle' => 'semiannual', 'generation_mode' => 'scheduled',
            'grace_days' => 15, 'legacy_frequency' => 'Semi-annual', 'sequence' => 50],
        ['code' => 'annual', 'label' => 'Annual', 'cycle' => 'annual', 'generation_mode' => 'scheduled',
            'grace_days' => 20, 'legacy_frequency' => 'Annual', 'sequence' => 60],
        // Continuous: a rolling task the officer records against. It has a
        // reporting window but no deadline, so it never appears overdue.
        ['code' => 'observation', 'label' => 'Observation', 'cycle' => 'continuous', 'generation_mode' => 'continuous',
            'grace_days' => 0, 'legacy_frequency' => 'Event-driven', 'sequence' => 70],
        // Event-driven. Three distinct triggers, kept distinct: an examiner
        // asking "what made this control fire" gets an answer.
        ['code' => 'on_request', 'label' => 'On request', 'cycle' => 'event', 'generation_mode' => 'event',
            'grace_days' => 5, 'trigger_event' => 'request_received', 'legacy_frequency' => 'Event-driven', 'sequence' => 80],
        ['code' => 'cbn_fx_sale', 'label' => 'As per CBN FX sale', 'cycle' => 'event', 'generation_mode' => 'event',
            'grace_days' => 2, 'trigger_event' => 'cbn_fx_sale', 'legacy_frequency' => 'Event-driven', 'sequence' => 90],
        ['code' => 'cbn_circular', 'label' => 'On a new CBN circular', 'cycle' => 'event', 'generation_mode' => 'event',
            'grace_days' => 5, 'trigger_event' => 'cbn_circular_published', 'legacy_frequency' => 'Event-driven', 'sequence' => 100],
    ];

    /**
     * The client's wording, verbatim from the workbook, plus the platform
     * enum spellings so a control created in the UI resolves too.
     *
     * `bi-annually` is read as twice a year: the source uses "twice
     * annually" and "Half yearly" alongside it for the same idea, and
     * Nigerian banking usage agrees. It is §G.1 of the change request —
     * confirm before go-live, change this row if the answer is otherwise.
     */
    private const ALIASES = [
        'daily' => ['Daily'],
        'weekly' => ['Weekly'],
        'monthly' => ['Monthly'],
        'quarterly' => ['Quarterly', 'Quaterly'],
        'semiannual' => ['Semi-annual', 'Semi annual', 'bi-annually', 'biannually', 'twice annually', 'Half yearly'],
        'annual' => ['Annual', 'Yearly', 'Annually'],
        'observation' => ['Observation'],
        'on_request' => ['On request', 'Event-driven', 'As requested'],
        'cbn_fx_sale' => ['As per sales by CBN', 'As per sale by CBN'],
        'cbn_circular' => ['Anytime there a new circular', 'Anytime there is a new circular'],
    ];

    public function run(): void
    {
        foreach (self::FREQUENCIES as $definition) {
            $frequency = ControlFrequency::updateOrCreate(
                ['tenant_id' => null, 'code' => $definition['code']],
                [...$definition, 'is_active' => true],
            );

            foreach (self::ALIASES[$definition['code']] ?? [] as $raw) {
                FrequencyAlias::updateOrCreate(
                    ['tenant_id' => null, 'normalised' => FrequencyAlias::normalise($raw)],
                    ['control_frequency_id' => $frequency->id, 'raw' => $raw],
                );
            }
        }

        app(FrequencyResolver::class)->flush();
    }
}
