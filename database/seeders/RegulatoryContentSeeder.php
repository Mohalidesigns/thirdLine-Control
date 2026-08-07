<?php

namespace Database\Seeders;

use App\Services\ContentPackInstaller;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Installs every content pack shipped in database/content-packs.
 *
 * The packs themselves are the regulatory content (R1) — this seeder holds
 * no framework, obligation, deadline or penalty of its own. Packs install
 * in the order below so cross-framework mappings find both ends: the
 * international frameworks first, then Nigeria, then the pan-African drafts,
 * then the mapping-only pack.
 *
 * Verification status travels with each record (R10). Only COSO IC 2013,
 * COSO ERM 2017 and the ISO 31000 principles ship 'verified' — their text is
 * reproduced from the specification in Part D of the build brief. Every
 * Nigerian pack ships 'unverified' and every pan-African pack ships 'draft'
 * until a human confirms each date, threshold and penalty against the
 * regulator's primary document; until then those records are excluded from
 * generated regulatory submissions.
 */
class RegulatoryContentSeeder extends Seeder
{
    public function run(): void
    {
        $installer = app(ContentPackInstaller::class);
        $packs = array_keys($installer->availablePacks());

        // Mapping-only packs install last: they need both ends present.
        usort($packs, fn ($a, $b) => [str_starts_with($a, 'CROSS-'), $a] <=> [str_starts_with($b, 'CROSS-'), $b]);

        foreach ($packs as $code) {
            try {
                $installer->install($code);
            } catch (Throwable $e) {
                $this->command?->warn("Content pack {$code} could not be installed: {$e->getMessage()}");
            }
        }

        $this->command?->info(count($packs).' content pack(s) processed.');
    }
}
