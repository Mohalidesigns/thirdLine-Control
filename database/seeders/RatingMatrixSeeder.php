<?php

namespace Database\Seeders;

use App\Models\RatingMatrixEntry;
use Illuminate\Database\Seeder;

class RatingMatrixSeeder extends Seeder
{
    /**
     * BRD §7.3 default matrix, stored as system rows (tenant_id null) so
     * the derivation is data-driven, never hard-coded (FR-7.4).
     */
    public function run(): void
    {
        $matrix = [
            'Effective' => [
                'Effective' => 'Effective',
                'Partially Effective' => 'Partially Effective',
                'Ineffective' => 'Ineffective',
                'Not Tested' => 'Not Tested',
            ],
            'Partially Effective' => [
                'Effective' => 'Partially Effective',
                'Partially Effective' => 'Partially Effective',
                'Ineffective' => 'Ineffective',
                'Not Tested' => 'Not Tested',
            ],
            'Ineffective' => [
                'Effective' => 'Ineffective',
                'Partially Effective' => 'Ineffective',
                'Ineffective' => 'Ineffective',
                'Not Tested' => 'Ineffective',
            ],
            'Not Tested' => [
                'Effective' => 'Not Tested',
                'Partially Effective' => 'Not Tested',
                'Ineffective' => 'Ineffective',
                'Not Tested' => 'Not Tested',
            ],
        ];

        foreach ($matrix as $design => $row) {
            foreach ($row as $operating => $overall) {
                RatingMatrixEntry::firstOrCreate(
                    ['tenant_id' => null, 'design_effectiveness' => $design, 'operating_effectiveness' => $operating],
                    ['overall_rating' => $overall],
                );
            }
        }
    }
}
