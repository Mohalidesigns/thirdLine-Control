<?php

namespace App\Integrations\Contracts;

/**
 * What a connector can and cannot do, declared rather than discovered.
 *
 * `verified` is the honest field: false means the mapping is documented
 * from vendor material but the transport has never been exercised against
 * a live environment. It surfaces in the UI as an explicit warning rather
 * than being quietly hidden (12.2).
 */
final class Capability
{
    /**
     * @param  array<int, string>  $authTypes
     * @param  array<int, string>  $datasets  dataset keys the connector knows how to map
     * @param  array<string, array<string, string>>  $fieldMappings  dataset key => [source field => canonical field]
     * @param  array<int, string>  $notCovered
     */
    public function __construct(
        public readonly string $vendorKey,
        public readonly string $label,
        public readonly string $transport,
        public readonly array $authTypes = [],
        public readonly bool $supportsIncremental = false,
        public readonly bool $supportsDiscovery = false,
        public readonly bool $supportsPagination = false,
        public readonly bool $verified = false,
        public readonly array $datasets = [],
        public readonly array $fieldMappings = [],
        public readonly array $notCovered = [],
        public readonly ?string $documentation = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'vendor_key' => $this->vendorKey,
            'label' => $this->label,
            'transport' => $this->transport,
            'auth_types' => $this->authTypes,
            'supports_incremental' => $this->supportsIncremental,
            'supports_discovery' => $this->supportsDiscovery,
            'supports_pagination' => $this->supportsPagination,
            'verified' => $this->verified,
            'datasets' => $this->datasets,
            'field_mappings' => $this->fieldMappings,
            'not_covered' => $this->notCovered,
            'documentation' => $this->documentation,
        ];
    }
}
