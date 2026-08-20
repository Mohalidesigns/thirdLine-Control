<?php

namespace App\Services;

use App\Models\ContentPack;
use App\Models\Control;
use App\Models\ControlCategory;
use App\Models\Framework;
use App\Models\FrameworkMapping;
use App\Models\FrameworkRequirement;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Installs versioned regulatory content packs (Part D §D.1).
 *
 * Everything a pack carries — the regulator, the framework, its requirement
 * tree, the obligations with their due rules and penalties, the suggested
 * controls and the cross-framework mappings — is data (R1). The installer
 * only ever writes global rows (tenant_id NULL), so a tenant's own or
 * customised records are structurally safe from an update; the diff report
 * names them so an operator knows which updates the tenant will not see.
 *
 * Records that carry verification_status other than 'verified' install
 * normally but stay excluded from generated regulatory submissions (R10).
 */
class ContentPackInstaller
{
    public function __construct(private FrameworkService $frameworks) {}

    public function path(): string
    {
        return database_path('content-packs');
    }

    /**
     * @return array<string, array<int, string>> pack code => versions, newest last
     */
    public function availablePacks(): array
    {
        if (! is_dir($this->path())) {
            return [];
        }

        $packs = [];

        foreach (glob($this->path().'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $versions = [];

            foreach (glob($directory.'/*.json') ?: [] as $file) {
                $versions[] = basename($file, '.json');
            }

            if ($versions === []) {
                continue;
            }

            usort($versions, 'version_compare');
            $packs[basename($directory)] = $versions;
        }

        ksort($packs);

        return $packs;
    }

    public function latestVersion(string $code): ?string
    {
        $versions = $this->availablePacks()[$code] ?? [];

        return $versions === [] ? null : end($versions);
    }

    /**
     * @return array{pack: array, checksum: string, version: string, file: string}
     */
    public function read(string $code, ?string $version = null): array
    {
        $version ??= $this->latestVersion($code);

        if (! $version) {
            throw new RuntimeException("No content pack found for code '{$code}'.");
        }

        $file = $this->path()."/{$code}/{$version}.json";

        if (! is_file($file)) {
            throw new RuntimeException("Content pack file not found: {$file}");
        }

        $raw = file_get_contents($file);
        $pack = json_decode($raw, true);

        if (! is_array($pack)) {
            throw new RuntimeException("Content pack {$code} {$version} is not valid JSON.");
        }

        $this->assertVerificationAttribution($pack, $code, $version);

        return [
            'pack' => $pack,
            'checksum' => hash('sha256', $raw),
            'version' => $version,
            'file' => $file,
        ];
    }

    /**
     * D.7 step 3, made enforceable rather than advisory.
     *
     * 'verified' is the status that lets a record into a generated regulatory
     * submission (R10), so a pack that claims it anywhere — on the pack, the
     * framework, a requirement or an obligation — must record who confirmed it
     * against the regulator's primary document and when. Without that the
     * claim is unattributable and the pack refuses to install.
     */
    private function assertVerificationAttribution(array $pack, string $code, string $version): void
    {
        $statuses = array_merge(
            [$pack['verification_status'] ?? 'unverified', $pack['framework']['verification_status'] ?? 'unverified'],
            array_column($pack['requirements'] ?? [], 'verification_status'),
            array_column($pack['obligations'] ?? [], 'verification_status'),
        );

        if (! in_array('verified', $statuses, true)) {
            return;
        }

        if (empty($pack['verified_by']) || empty($pack['verified_at'])) {
            throw new RuntimeException(
                "Content pack {$code} {$version} ships verified records but carries no verified_by/verified_at ".
                '(Part D §D.7). Record the verifier and the date, or demote the records to unverified.'
            );
        }
    }

    /**
     * What an install would change. Produced before every apply, and the
     * whole output of a --dry-run.
     */
    public function plan(array $pack): array
    {
        // A mapping-only pack carries no framework of its own.
        $frameworkCode = $pack['framework']['code'] ?? null;
        $frameworkVersion = $pack['framework']['version'] ?? $pack['version'] ?? '1.0.0';

        $existing = $frameworkCode
            ? Framework::withoutGlobalScopes()->whereNull('tenant_id')
                ->where('code', $frameworkCode)->where('version', $frameworkVersion)->first()
            : null;

        $existingRefs = $existing
            ? FrameworkRequirement::where('framework_id', $existing->id)->pluck('ref_code')->all()
            : [];

        $packRefs = array_values(array_filter(array_map(
            fn ($r) => $r['ref_code'] ?? null, $pack['requirements'] ?? []
        )));

        $obligationRefs = array_values(array_filter(array_map(
            fn ($o) => $o['obligation_ref'] ?? null, $pack['obligations'] ?? []
        )));

        $existingObligations = RegulatoryObligation::withoutGlobalScopes()->whereNull('tenant_id')
            ->whereIn('obligation_ref', $obligationRefs ?: ['—'])->pluck('obligation_ref')->all();

        // Tenant-authored rows the installer must not touch.
        $tenantOverrides = RegulatoryObligation::withoutGlobalScopes()->whereNotNull('tenant_id')
            ->whereIn('obligation_ref', $obligationRefs ?: ['—'])->pluck('obligation_ref')->unique()->values()->all();

        $tenantFrameworkOverrides = $frameworkCode
            ? Framework::withoutGlobalScopes()->whereNotNull('tenant_id')
                ->where('code', $frameworkCode)->pluck('code')->unique()->values()->all()
            : [];

        return [
            'code' => $pack['code'] ?? $frameworkCode,
            'name' => $pack['name'] ?? null,
            'version' => $pack['version'] ?? $frameworkVersion,
            'jurisdiction' => $pack['jurisdiction'] ?? 'INTL',
            'framework' => [
                'code' => $frameworkCode,
                'action' => $frameworkCode === null ? 'none' : ($existing ? 'update' : 'create'),
            ],
            'requirements' => [
                'create' => count(array_diff($packRefs, $existingRefs)),
                'update' => count(array_intersect($packRefs, $existingRefs)),
                // Rows present in the database but absent from the pack are
                // left alone: a pack never deletes.
                'orphaned' => count(array_diff($existingRefs, $packRefs)),
            ],
            'obligations' => [
                'create' => count(array_diff($obligationRefs, $existingObligations)),
                'update' => count(array_intersect($obligationRefs, $existingObligations)),
            ],
            'regulator' => isset($pack['regulator']['code']) ? $pack['regulator']['code'] : null,
            'controls' => count($pack['controls'] ?? []),
            'mappings' => count($pack['mappings'] ?? []),
            'tenant_overrides' => [
                'frameworks' => $tenantFrameworkOverrides,
                'obligations' => $tenantOverrides,
            ],
            'verification_summary' => $this->verificationSummary($pack),
        ];
    }

    /**
     * @return array{plan: array, installed: bool, pack: ContentPack|null}
     */
    public function install(string $code, ?string $version = null, bool $dryRun = false, ?User $user = null): array
    {
        ['pack' => $pack, 'checksum' => $checksum, 'version' => $resolvedVersion] = $this->read($code, $version);

        $plan = $this->plan($pack);

        if ($dryRun) {
            return ['plan' => $plan, 'installed' => false, 'pack' => null];
        }

        $record = DB::transaction(function () use ($pack, $code, $resolvedVersion, $checksum, $user, $plan) {
            $regulator = $this->installRegulator($pack['regulator'] ?? null);
            $framework = isset($pack['framework']['code'])
                ? $this->frameworks->import($pack, null, $user)
                : null;

            $this->installObligations($pack['obligations'] ?? [], $regulator, $framework);
            $this->installMappings($pack['mappings'] ?? [], $framework);
            $this->installSuggestedControls($pack['controls'] ?? [], $code);

            $record = ContentPack::updateOrCreate(
                ['code' => $code, 'version' => $resolvedVersion],
                [
                    'name' => $pack['name'] ?? $code,
                    'jurisdiction' => $pack['jurisdiction'] ?? 'INTL',
                    'checksum' => $checksum,
                    'published_at' => $pack['published_at'] ?? null,
                    'installed_at' => now(),
                    'installed_by' => $user?->id,
                    'changelog' => $pack['changelog'] ?? null,
                    'verification_summary' => $plan['verification_summary'],
                ],
            );

            $record->auditAction('content-pack-installed', null, [
                'code' => $code,
                'version' => $resolvedVersion,
                'checksum' => $checksum,
                'plan' => $plan,
            ]);

            return $record;
        });

        return ['plan' => $plan, 'installed' => true, 'pack' => $record];
    }

    // ── Sections ─────────────────────────────────────────────────────────

    private function installRegulator(?array $definition): ?Regulator
    {
        if (! $definition || empty($definition['code'])) {
            return null;
        }

        return Regulator::updateOrCreate(
            ['code' => $definition['code']],
            [
                'name' => $definition['name'] ?? $definition['code'],
                'jurisdiction' => $definition['jurisdiction'] ?? 'NG',
                'sector' => $definition['sector'] ?? null,
                'website' => $definition['website'] ?? null,
                'portal_url' => $definition['portal_url'] ?? null,
                'contact_details' => $definition['contact_details'] ?? null,
                'feed_url' => $definition['feed_url'] ?? null,
                'is_active' => $definition['is_active'] ?? true,
            ],
        );
    }

    /**
     * @param  array<int, array>  $obligations
     */
    private function installObligations(array $obligations, ?Regulator $regulator, ?Framework $framework): void
    {
        foreach ($obligations as $row) {
            if (empty($row['obligation_ref'])) {
                continue;
            }

            $obligationRegulator = isset($row['regulator_code'])
                ? Regulator::where('code', $row['regulator_code'])->first()
                : $regulator;

            if (! $obligationRegulator) {
                continue;
            }

            $requirementId = null;

            if ($framework && ! empty($row['requirement_ref'])) {
                $requirementId = FrameworkRequirement::where('framework_id', $framework->id)
                    ->where('ref_code', $row['requirement_ref'])->value('id');
            }

            RegulatoryObligation::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => null, 'obligation_ref' => $row['obligation_ref']],
                [
                    'regulator_id' => $obligationRegulator->id,
                    'framework_id' => $framework?->id,
                    'requirement_id' => $requirementId,
                    'title' => $row['title'] ?? $row['obligation_ref'],
                    'description' => $row['description'] ?? null,
                    'obligation_type' => $row['obligation_type'] ?? 'filing',
                    'applies_to' => $row['applies_to'] ?? null,
                    'trigger_type' => $row['trigger_type'] ?? 'calendar',
                    'frequency' => $row['frequency'] ?? 'annual',
                    'due_rule' => $row['due_rule'] ?? ['type' => 'fixed_date', 'month' => 12, 'day' => 31],
                    'grace_period_days' => (int) ($row['grace_period_days'] ?? 0),
                    'penalty_description' => $row['penalty_description'] ?? null,
                    'penalty_amount_minor' => $row['penalty_amount_minor'] ?? null,
                    'penalty_fixed_amount_minor' => $row['penalty_fixed_amount_minor'] ?? null,
                    'penalty_currency' => $row['penalty_currency'] ?? null,
                    'penalty_basis' => $row['penalty_basis'] ?? null,
                    'legal_reference' => $row['legal_reference'] ?? null,
                    'source_url' => $row['source_url'] ?? null,
                    'effective_from' => $row['effective_from'] ?? null,
                    'effective_to' => $row['effective_to'] ?? null,
                    'verification_status' => $row['verification_status'] ?? 'unverified',
                    'is_active' => $row['is_active'] ?? true,
                ],
            );
        }
    }

    /**
     * Cross-framework edges. A mapping whose far side has not been installed
     * yet is skipped silently — install the other pack and re-run.
     *
     * @param  array<int, array>  $mappings
     */
    private function installMappings(array $mappings, ?Framework $framework): void
    {
        foreach ($mappings as $row) {
            $source = $this->resolveRequirement($row['from_framework'] ?? $framework?->code, $row['from_ref'] ?? null);
            $target = $this->resolveRequirement($row['to_framework'] ?? null, $row['to_ref'] ?? null);

            if (! $source || ! $target || $source->id === $target->id) {
                continue;
            }

            FrameworkMapping::updateOrCreate(
                ['source_requirement_id' => $source->id, 'target_requirement_id' => $target->id],
                [
                    'relationship' => $row['relationship'] ?? 'supports',
                    'coverage_percentage' => (int) ($row['coverage_percentage'] ?? 100),
                    'rationale' => $row['rationale'] ?? null,
                ],
            );
        }
    }

    private function resolveRequirement(?string $frameworkCode, ?string $refCode): ?FrameworkRequirement
    {
        if (! $frameworkCode || ! $refCode) {
            return null;
        }

        $frameworkId = Framework::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('code', $frameworkCode)->orderByDesc('id')->value('id');

        if (! $frameworkId) {
            return null;
        }

        return FrameworkRequirement::where('framework_id', $frameworkId)->where('ref_code', $refCode)->first();
    }

    /**
     * Suggested controls ship as adoptable templates in every tenant's
     * starter library. An existing template with the same reference is
     * refreshed; a tenant's own (non-template) control is never touched.
     *
     * @param  array<int, array>  $controls
     */
    private function installSuggestedControls(array $controls, string $packCode): void
    {
        if ($controls === []) {
            return;
        }

        $tenants = Tenant::query()->get(['id']);

        foreach ($tenants as $tenant) {
            foreach ($controls as $index => $row) {
                $reference = $row['control_ref'] ?? sprintf('TPL-%s-%03d', $packCode, $index + 1);

                $categoryId = isset($row['category'])
                    ? ControlCategory::withoutGlobalScopes()->firstOrCreate(
                        ['name' => $row['category'], 'tenant_id' => null], ['is_system' => true]
                    )->id
                    : null;

                Control::withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'control_ref' => $reference],
                    [
                        'title' => $row['title'] ?? $reference,
                        'description' => $row['description'] ?? null,
                        'objective' => $row['objective'] ?? null,
                        'type' => $row['type'] ?? 'Preventive',
                        'nature' => $row['nature'] ?? 'Manual',
                        'category_id' => $categoryId,
                        'coso_component' => $row['coso_component'] ?? null,
                        'frequency' => $row['frequency'] ?? 'Quarterly',
                        'is_key_control' => (bool) ($row['is_key_control'] ?? true),
                        'is_template' => true,
                        'status' => 'Active',
                        'framework_refs' => $row['framework_refs'] ?? [$packCode],
                    ],
                );
            }
        }
    }

    /**
     * How much of this pack is safe to file with a regulator (R10).
     */
    private function verificationSummary(array $pack): array
    {
        $tally = function (array $rows, string $fallback): array {
            $counts = ['verified' => 0, 'unverified' => 0, 'draft' => 0];

            foreach ($rows as $row) {
                $status = $row['verification_status'] ?? $fallback;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            return $counts;
        };

        $packStatus = $pack['verification_status'] ?? 'unverified';

        return [
            'pack' => $packStatus,
            'requirements' => $tally($pack['requirements'] ?? [], $packStatus),
            'obligations' => $tally($pack['obligations'] ?? [], $packStatus),
            'verified_by' => $pack['verified_by'] ?? null,
            'verified_at' => $pack['verified_at'] ?? null,
        ];
    }
}
