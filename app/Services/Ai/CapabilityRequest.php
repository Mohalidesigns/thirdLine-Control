<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One call into the gateway, fully described.
 *
 * Readonly because the pipeline is a sequence of checks against it — a
 * request that could be mutated between the authorisation check and the
 * egress check is a request whose checks mean nothing.
 */
class CapabilityRequest
{
    /**
     * @param  string  $capabilityKey  which of the nine
     * @param  User  $user  the requester; retrieval is filtered to their permissions
     * @param  array<string, mixed>  $variables  prompt template variables, pre-redaction
     * @param  Model|null  $subject  the record this attaches to in the audit log
     * @param  string  $retrievalQuery  what to search the tenant's own records for
     * @param  array<int, string>  $sources  retrieval sources, empty for the capability default
     * @param  array<string, mixed>  $meta  anything the capability needs on the way back
     */
    public function __construct(
        public readonly string $capabilityKey,
        public readonly User $user,
        public readonly array $variables = [],
        public readonly ?Model $subject = null,
        public readonly string $retrievalQuery = '',
        public readonly array $sources = [],
        public readonly array $meta = [],
    ) {}

    public function withVariables(array $variables): self
    {
        return new self(
            $this->capabilityKey,
            $this->user,
            [...$this->variables, ...$variables],
            $this->subject,
            $this->retrievalQuery,
            $this->sources,
            $this->meta,
        );
    }

    /** @return array<int, string> */
    public function effectiveSources(): array
    {
        return $this->sources !== []
            ? $this->sources
            : CapabilityRegistry::sources($this->capabilityKey);
    }

    public function definition(): array
    {
        return CapabilityRegistry::definition($this->capabilityKey);
    }
}
