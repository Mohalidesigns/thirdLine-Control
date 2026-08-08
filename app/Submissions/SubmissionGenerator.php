<?php

namespace App\Submissions;

use App\Models\ControlRequirementMap;
use App\Models\Framework;
use App\Models\FrameworkRequirement;
use App\Models\OrganisationUnit;
use App\Models\RegulatoryObligation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One regulator-shaped return (13.4).
 *
 * Nothing a regulator asks for is written into a subclass: the requirement
 * list, the deadline and the penalty all come from the installed content pack
 * (R1). A generator's job is to answer those requirements from live data,
 * say honestly what it could not answer, and refuse to put unverified
 * regulatory text into a filed document (R10).
 */
abstract class SubmissionGenerator
{
    abstract public function packType(): string;

    abstract public function label(): string;

    abstract public function regulatorCode(): string;

    abstract public function frameworkCode(): string;

    /** The obligation_ref in the content pack this return discharges. */
    abstract public function obligationRef(): ?string;

    abstract public function description(): string;

    abstract public function generate(User $user, array $options = []): GeneratedPack;

    /** @return array<int, array<string, mixed>> */
    public function signatories(): array
    {
        return [];
    }

    public function defaultPeriodLabel(): string
    {
        return 'FY '.now()->subYear()->year;
    }

    /** Descriptor for the "new submission" picker. */
    public function toArray(): array
    {
        return [
            'pack_type' => $this->packType(),
            'label' => $this->label(),
            'regulator' => $this->regulatorCode(),
            'framework' => $this->frameworkCode(),
            'description' => $this->description(),
            'default_period' => $this->defaultPeriodLabel(),
            'installed' => $this->framework() !== null,
        ];
    }

    // ── Content-pack lookups ─────────────────────────────────────────────

    protected function framework(): ?Framework
    {
        return Framework::where('code', $this->frameworkCode())->first();
    }

    protected function obligation(): ?RegulatoryObligation
    {
        return $this->obligationRef()
            ? RegulatoryObligation::where('obligation_ref', $this->obligationRef())->first()
            : null;
    }

    /** @return Collection<int, FrameworkRequirement> */
    protected function requirements(): Collection
    {
        $framework = $this->framework();

        if (! $framework) {
            return collect();
        }

        return FrameworkRequirement::where('framework_id', $framework->id)
            ->orderBy('level')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * R10, mechanised. Requirements whose text has not been confirmed against
     * the regulator's own document are split out here: the verified ones go
     * into the return, the rest are named in unverified_items and left out.
     * A submission built on text nobody has checked is a submission nobody
     * should sign.
     *
     * @param  Collection<int, FrameworkRequirement>  $requirements
     * @return array{0: Collection<int, FrameworkRequirement>, 1: array<int, array<string, mixed>>}
     */
    protected function splitByVerification(Collection $requirements): array
    {
        $verified = $requirements->where('verification_status', 'verified')->values();

        $unverified = $requirements
            ->where('verification_status', '!=', 'verified')
            ->map(fn (FrameworkRequirement $requirement) => [
                'ref' => $requirement->ref_code,
                'title' => $requirement->title,
                'status' => $requirement->verification_status,
                'reason' => 'Excluded from the generated document — this requirement has not been verified '
                    .'against the regulator\'s primary document.',
            ])
            ->values()
            ->all();

        return [$verified, $unverified];
    }

    /**
     * Which requirements already have an approved mapping to an active
     * control — the evidence that lets the generator pre-answer "applied?".
     *
     * @param  Collection<int, FrameworkRequirement>  $requirements
     * @return array<int, array<int, array<string, mixed>>> requirement_id => controls
     */
    protected function mappedControls(Collection $requirements): array
    {
        if ($requirements->isEmpty()) {
            return [];
        }

        return ControlRequirementMap::query()
            ->approved()
            ->whereIn('requirement_id', $requirements->pluck('id'))
            ->join('controls', 'controls.id', '=', 'control_requirement_map.control_id')
            ->whereNull('controls.deleted_at')
            ->where('controls.status', 'Active')
            ->get([
                'control_requirement_map.requirement_id',
                'controls.id as control_id',
                'controls.control_ref',
                'controls.title',
                'controls.operating_effectiveness',
            ])
            ->groupBy('requirement_id')
            ->map(fn ($group) => $group->map(fn ($row) => [
                'id' => $row->control_id,
                'ref' => $row->control_ref,
                'title' => $row->title,
                'operating_effectiveness' => $row->operating_effectiveness,
            ])->all())
            ->all();
    }

    /**
     * The filing entity: the one named on the request, else the tenant's
     * first declared legal entity, else its top unit. A tenant that has not
     * flagged which unit is the legal entity still gets a working pack —
     * with a name the filer can correct — rather than a blank return.
     */
    protected function entity(array $options): ?OrganisationUnit
    {
        if (! empty($options['entity_id'])) {
            return OrganisationUnit::find($options['entity_id']);
        }

        return OrganisationUnit::where('is_legal_entity', true)->orderBy('id')->first()
            ?? OrganisationUnit::orderBy('id')->first();
    }

    // ── Section helpers ──────────────────────────────────────────────────

    protected function field(
        string $key,
        string $label,
        mixed $value = null,
        bool $required = true,
        string $source = 'input',
        ?string $note = null,
        array $options = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'required' => $required,
            // 'system' fields were answered from platform data and are shown
            // read-only; 'input' fields are the human's to complete.
            'source' => $source,
            'note' => $note,
            'options' => $options,
        ];
    }

    protected function section(string $key, string $title, array $fields = [], array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'title' => $title,
            'fields' => $fields,
        ], $extra);
    }

    protected function error(string $code, string $message, bool $blocking = true): array
    {
        return ['code' => $code, 'message' => $message, 'blocking' => $blocking];
    }

    /**
     * Carry a human's typed answers over a freshly-derived shell, so
     * regenerating a pack against newer data never destroys work. A
     * system-derived value stays authoritative — the whole point of
     * regenerating is that those numbers move.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function mergeExisting(array $sections, array $existing): array
    {
        $answers = [];

        foreach ($existing['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $value = $field['value'] ?? null;

                if ($value !== null && $value !== '' && $value !== []) {
                    $answers[$field['key']] = $value;
                }
            }
        }

        if ($answers === []) {
            return $sections;
        }

        return array_map(function (array $section) use ($answers) {
            $section['fields'] = array_map(function (array $field) use ($answers) {
                if (($field['source'] ?? 'input') !== 'system' && array_key_exists($field['key'], $answers)) {
                    $field['value'] = $answers[$field['key']];
                }

                return $field;
            }, $section['fields'] ?? []);

            return $section;
        }, $sections);
    }

    /** The error every generator raises when its content pack is missing. */
    protected function missingFrameworkError(): array
    {
        return $this->error(
            'framework_missing',
            "The {$this->frameworkCode()} content pack is not installed, so this return cannot be built. "
            .'Install it from Administration → Content packs.',
        );
    }
}
