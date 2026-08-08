<?php

namespace App\Submissions;

use App\Submissions\Generators\CbnInternalAuditGenerator;
use App\Submissions\Generators\FrcCg001Generator;
use App\Submissions\Generators\FrcIcfrGenerator;
use App\Submissions\Generators\NdicDpasGenerator;
use App\Submissions\Generators\NdpcCarGenerator;
use App\Submissions\Generators\SecNigeriaGenerator;
use Illuminate\Validation\ValidationException;

/**
 * The submission generators this installation ships (13.4). A pack type that
 * is not here cannot be generated — the pack_type on a record is an
 * allowlist entry, not free text.
 */
class SubmissionPackRegistry
{
    /** @var array<int, class-string<SubmissionGenerator>> */
    private const GENERATORS = [
        FrcCg001Generator::class,
        NdpcCarGenerator::class,
        FrcIcfrGenerator::class,
        CbnInternalAuditGenerator::class,
        NdicDpasGenerator::class,
        SecNigeriaGenerator::class,
    ];

    /** @var array<string, SubmissionGenerator>|null */
    private ?array $generators = null;

    /** @return array<string, SubmissionGenerator> */
    public function all(): array
    {
        if ($this->generators !== null) {
            return $this->generators;
        }

        $generators = [];

        foreach (self::GENERATORS as $class) {
            $generator = app($class);
            $generators[$generator->packType()] = $generator;
        }

        return $this->generators = $generators;
    }

    public function find(string $packType): ?SubmissionGenerator
    {
        return $this->all()[$packType] ?? null;
    }

    public function get(string $packType): SubmissionGenerator
    {
        return $this->find($packType) ?? throw ValidationException::withMessages([
            'pack_type' => "'{$packType}' is not a submission type this platform generates.",
        ]);
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    /** @return array<int, array<string, mixed>> */
    public function catalogue(): array
    {
        return array_values(array_map(
            fn (SubmissionGenerator $generator) => $generator->toArray(),
            $this->all(),
        ));
    }
}
