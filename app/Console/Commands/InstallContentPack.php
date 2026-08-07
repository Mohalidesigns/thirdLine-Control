<?php

namespace App\Console\Commands;

use App\Services\ContentPackInstaller;
use Illuminate\Console\Command;
use RuntimeException;

class InstallContentPack extends Command
{
    protected $signature = 'atheris:install-content-pack
        {code? : Pack code, e.g. COSO-IC-2013. Omit with --all to install every pack}
        {--pack-version= : Pack version (defaults to the newest on disk; --version is reserved by the framework)}
        {--dry-run : Print the diff report without writing anything}
        {--all : Install every pack found in database/content-packs}
        {--list : List the available packs and exit}';

    protected $description = 'Install a versioned regulatory content pack — idempotent, checksummed, and never overwrites tenant customisations';

    public function handle(ContentPackInstaller $installer): int
    {
        $available = $installer->availablePacks();

        if ($this->option('list')) {
            $this->table(
                ['Pack', 'Versions'],
                collect($available)->map(fn ($versions, $code) => [$code, implode(', ', $versions)])->values()->all(),
            );

            return self::SUCCESS;
        }

        $codes = $this->option('all')
            ? array_keys($available)
            : array_filter([$this->argument('code')]);

        if ($codes === []) {
            $this->error('Give a pack code, or --all. Use --list to see what is available.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($codes as $code) {
            try {
                $result = $installer->install(
                    $code,
                    $this->option('all') ? null : $this->option('pack-version'),
                    (bool) $this->option('dry-run'),
                );

                $this->report($code, $result);
            } catch (RuntimeException $e) {
                $this->error("{$code}: {$e->getMessage()}");
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->warn("{$failed} pack(s) failed to install.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function report(string $code, array $result): void
    {
        $plan = $result['plan'];
        $mode = $result['installed'] ? 'installed' : 'dry run';

        $this->line("<info>{$code}</info> {$plan['version']} ({$plan['jurisdiction']}) — {$mode}");

        $this->table(
            ['Section', 'Create', 'Update', 'Notes'],
            [
                ['Framework', $plan['framework']['action'] === 'create' ? 1 : 0, $plan['framework']['action'] === 'update' ? 1 : 0, $plan['framework']['code'] ?? '—'],
                ['Requirements', $plan['requirements']['create'], $plan['requirements']['update'], $plan['requirements']['orphaned'].' in DB but not in pack (left alone)'],
                ['Obligations', $plan['obligations']['create'], $plan['obligations']['update'], $plan['regulator'] ?? '—'],
                ['Suggested controls', $plan['controls'], 0, 'installed as adoptable templates'],
                ['Cross-framework mappings', $plan['mappings'], 0, 'skipped where the far side is not installed'],
            ],
        );

        $summary = $plan['verification_summary'];
        $this->line(sprintf(
            '  Verification — pack: %s · requirements verified/unverified/draft: %d/%d/%d · obligations: %d/%d/%d',
            $summary['pack'],
            $summary['requirements']['verified'], $summary['requirements']['unverified'], $summary['requirements']['draft'],
            $summary['obligations']['verified'], $summary['obligations']['unverified'], $summary['obligations']['draft'],
        ));

        if ($summary['pack'] !== 'verified') {
            $this->warn('  Records that are not "verified" are excluded from generated regulatory submissions (R10).');
        }

        foreach ($plan['tenant_overrides']['obligations'] as $ref) {
            $this->warn("  Tenant customisation preserved — obligation {$ref} was not modified.");
        }

        foreach ($plan['tenant_overrides']['frameworks'] as $frameworkCode) {
            $this->warn("  Tenant customisation preserved — framework {$frameworkCode} was not modified.");
        }
    }
}
