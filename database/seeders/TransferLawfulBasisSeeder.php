<?php

namespace Database\Seeders;

use App\Models\TransferLawfulBasis;
use Illuminate\Database\Seeder;

/**
 * NDPA Part VIII cross-border transfer grounds (16.2). Lawful bases are
 * seeded data, never constants in a service (R1).
 *
 * Every basis ships 'unverified' (R10): the descriptions summarise the
 * grounds in NDPA 2023 Part VIII and the NDPC GAID as generally
 * understood, but the exact section numbers have not been confirmed
 * against the gazetted Act here, so legal_reference deliberately cites
 * the Part, not a section. Verify against the gazette, then flip to
 * 'verified' — until then the register still works, but a generated NDPC
 * submission flags the basis as unverified.
 */
class TransferLawfulBasisSeeder extends Seeder
{
    private const BASES = [
        [
            'code' => 'ADEQUACY',
            'name' => 'Adequacy of protection in the recipient country',
            'description' => 'The recipient country, or the recipient itself, is subject to a law, binding instrument or NDPC adequacy finding that provides protection materially similar to the NDPA.',
        ],
        [
            'code' => 'INSTRUMENT',
            'name' => 'Approved transfer instrument (contractual clauses / binding corporate rules)',
            'description' => 'The transfer is governed by an instrument the NDPC recognises — approved contractual clauses, binding corporate rules or a code of conduct — that binds the recipient to NDPA-equivalent protection.',
        ],
        [
            'code' => 'CONSENT',
            'name' => 'Informed consent of the data subject',
            'description' => 'The data subject consented to the specific transfer after being informed of the possible risks of a transfer to a country without adequate protection.',
        ],
        [
            'code' => 'CONTRACT',
            'name' => 'Necessary for a contract with the data subject',
            'description' => 'The transfer is necessary for the performance of a contract with the data subject, or for pre-contractual steps taken at the data subject\'s request.',
        ],
        [
            'code' => 'DS-BENEFIT',
            'name' => 'Sole benefit of the data subject',
            'description' => 'The transfer is for the sole benefit of the data subject and consent could not reasonably be obtained.',
        ],
        [
            'code' => 'PUBLIC-INTEREST',
            'name' => 'Important public interest',
            'description' => 'The transfer is necessary for an important reason of public interest recognised by law.',
        ],
        [
            'code' => 'LEGAL-CLAIM',
            'name' => 'Legal claims',
            'description' => 'The transfer is necessary for the establishment, exercise or defence of a legal claim.',
        ],
        [
            'code' => 'VITAL-INTEREST',
            'name' => 'Vital interests',
            'description' => 'The transfer is necessary to protect the vital interests of the data subject or another person, where consent cannot be obtained in time.',
        ],
        [
            'code' => 'NON-PERSONAL',
            'name' => 'No personal data in the transfer',
            'description' => 'The transfer contains no personal data (aggregates, configuration, anonymised statistics), so Part VIII does not bite — recorded so the register shows the classification decision, not an absence.',
        ],
    ];

    public function run(): void
    {
        foreach (self::BASES as $index => $basis) {
            TransferLawfulBasis::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => null, 'code' => $basis['code']],
                [
                    ...$basis,
                    'legal_reference' => $basis['code'] === 'NON-PERSONAL'
                        ? 'NDPA 2023 (scope) — classification decision'
                        : 'NDPA 2023, Part VIII (verify section against the gazette)',
                    'verification_status' => 'unverified',
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
