<?php

namespace Database\Seeders;

use App\Models\SustainabilityTopic;
use Illuminate\Database\Seeder;

/**
 * The shipped IFRS S1/S2 topic register (17.3).
 *
 * EVERY ROW SHIPS UNVERIFIED. The topic titles and pillar groupings below
 * are a working starting set drawn from the shape of IFRS S1 (general
 * requirements) and IFRS S2 (climate-related disclosures) — governance,
 * strategy, risk management, and metrics and targets — not a transcription
 * of the standards' paragraph numbers. Standard references are therefore
 * left null rather than guessed: an invented paragraph citation in a
 * compliance product is worse than none at all (R10).
 *
 * A tenant verifies a topic once an officer has checked it against the
 * IFRS Foundation's own text, at which point it becomes eligible for a
 * generated submission. Until then it is visible, usable for internal
 * planning, and excluded from anything filed.
 */
class SustainabilityTopicSeeder extends Seeder
{
    /** @var array<int, array{code: string, title: string, pillar: string, standard: string, description: string}> */
    private const TOPICS = [
        // ── IFRS S1 — general requirements ───────────────────────────
        [
            'code' => 'S1-GOV',
            'title' => 'Governance of sustainability-related risks and opportunities',
            'pillar' => 'governance',
            'standard' => 'IFRS S1',
            'description' => 'The board and management processes, controls and procedures used to monitor and manage sustainability-related risks and opportunities.',
        ],
        [
            'code' => 'S1-STRAT',
            'title' => 'Strategy and business model resilience',
            'pillar' => 'governance',
            'standard' => 'IFRS S1',
            'description' => 'How sustainability-related risks and opportunities affect the business model, strategy and cash flows over the short, medium and long term.',
        ],
        [
            'code' => 'S1-RISK',
            'title' => 'Sustainability risk identification and management',
            'pillar' => 'governance',
            'standard' => 'IFRS S1',
            'description' => 'The processes used to identify, assess, prioritise and monitor sustainability-related risks, and how they are integrated with overall risk management.',
        ],
        [
            'code' => 'S1-METRICS',
            'title' => 'Metrics and targets for sustainability performance',
            'pillar' => 'governance',
            'standard' => 'IFRS S1',
            'description' => 'The measures used to assess performance against sustainability-related targets, including targets set by law or regulation.',
        ],
        [
            'code' => 'S1-CONNECT',
            'title' => 'Connectivity with the financial statements',
            'pillar' => 'governance',
            'standard' => 'IFRS S1',
            'description' => 'Consistency of data, assumptions and reporting period between the sustainability disclosures and the financial statements.',
        ],

        // ── IFRS S2 — climate ────────────────────────────────────────
        [
            'code' => 'S2-GHG-1',
            'title' => 'Scope 1 gross greenhouse gas emissions',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'Direct emissions from sources owned or controlled by the entity.',
        ],
        [
            'code' => 'S2-GHG-2',
            'title' => 'Scope 2 gross greenhouse gas emissions',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'Indirect emissions from purchased energy, disclosed on a location-based and, where relevant, market-based measurement.',
        ],
        [
            'code' => 'S2-GHG-3',
            'title' => 'Scope 3 gross greenhouse gas emissions',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'Value-chain emissions. Deferrable in the first reporting period under the transition reliefs; a deferral is recorded on each figure with its reason.',
        ],
        [
            'code' => 'S2-TRANSITION',
            'title' => 'Climate transition plan',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'The entity\'s plan for responding to climate-related risks and opportunities, including targets and how they will be met.',
        ],
        [
            'code' => 'S2-SCENARIO',
            'title' => 'Climate resilience and scenario analysis',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'The scenario analysis performed to assess climate resilience, its inputs, and the evidence retained for it.',
        ],
        [
            'code' => 'S2-PHYSICAL',
            'title' => 'Physical climate risk exposure',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'Assets and business activities vulnerable to acute and chronic physical climate risks.',
        ],
        [
            'code' => 'S2-TRANSITION-RISK',
            'title' => 'Transition risk exposure',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'Exposure to policy, legal, technology, market and reputational risks arising from the transition to a lower-carbon economy — for a lender, principally through the loan book.',
        ],
        [
            'code' => 'S2-FINANCED',
            'title' => 'Financed emissions',
            'pillar' => 'environmental',
            'standard' => 'IFRS S2',
            'description' => 'Emissions associated with lending and investment activity. Material for most financial institutions and typically the largest Scope 3 category.',
        ],

        // ── Social and governance topics commonly material in the
        //    Nigerian financial sector. Entity-specific by nature: every
        //    tenant re-tests these against its own operations.
        [
            'code' => 'SOC-INCLUSION',
            'title' => 'Financial inclusion and access',
            'pillar' => 'social',
            'standard' => 'Entity-specific',
            'description' => 'Reach of services to underbanked populations, agent-banking footprint and access-related conduct outcomes.',
        ],
        [
            'code' => 'SOC-CONDUCT',
            'title' => 'Customer treatment and conduct outcomes',
            'pillar' => 'social',
            'standard' => 'Entity-specific',
            'description' => 'Fair treatment, complaint outcomes and product suitability. Draws on the consumer-protection complaint register.',
        ],
        [
            'code' => 'SOC-DATA',
            'title' => 'Data protection and customer privacy',
            'pillar' => 'social',
            'standard' => 'Entity-specific',
            'description' => 'NDPA compliance, cross-border transfer governance and breach handling.',
        ],
        [
            'code' => 'SOC-WORKFORCE',
            'title' => 'Workforce composition, health and safety',
            'pillar' => 'social',
            'standard' => 'Entity-specific',
            'description' => 'Employment practices, diversity of the workforce and board, and health and safety outcomes.',
        ],
        [
            'code' => 'GOV-ETHICS',
            'title' => 'Business ethics, anti-bribery and corruption',
            'pillar' => 'governance',
            'standard' => 'Entity-specific',
            'description' => 'Ethics programme, whistleblowing channel effectiveness and confirmed incidents.',
        ],
        [
            'code' => 'GOV-SUPPLY',
            'title' => 'Responsible sourcing and third-party conduct',
            'pillar' => 'governance',
            'standard' => 'Entity-specific',
            'description' => 'Sustainability conduct expectations placed on third parties, and how they are assessed. Draws on the third-party register.',
        ],
    ];

    public function run(): void
    {
        foreach (self::TOPICS as $index => $topic) {
            SustainabilityTopic::updateOrCreate(
                ['tenant_id' => null, 'code' => $topic['code']],
                [
                    'title' => $topic['title'],
                    'description' => $topic['description'],
                    'pillar' => $topic['pillar'],
                    'standard' => $topic['standard'],
                    // Deliberately null: see the class docblock. A guessed
                    // paragraph reference would be a fabricated citation.
                    'standard_reference' => null,
                    'verification_status' => 'unverified',
                    'verification_note' => 'Shipped starting set. Confirm the title, scope and paragraph reference against the '
                        .$topic['standard'].' text before this topic is used in a filed disclosure.',
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
