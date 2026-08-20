<?php

namespace App\Integrations\Contracts;

/**
 * One extractable collection on a source, as the connector describes it.
 * `piiClassification` is the connector's recommendation; the tenant's own
 * declaration on the dataset row is what SnapshotService acts on (12.7).
 */
final class DatasetDescriptor
{
    /**
     * @param  array<int, string>  $primaryKeyFields
     * @param  array<string, string>  $schema  field => type
     * @param  array<string, mixed>  $extractionConfig
     * @param  array<int, string>  $piiFields
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly array $primaryKeyFields = [],
        public readonly array $schema = [],
        public readonly array $extractionConfig = [],
        public readonly ?string $incrementalField = null,
        public readonly string $piiClassification = 'none',
        public readonly array $piiFields = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'primary_key_fields' => $this->primaryKeyFields,
            'schema_definition' => $this->schema,
            'extraction_config' => $this->extractionConfig,
            'incremental_field' => $this->incrementalField,
            'pii_classification' => $this->piiClassification,
            'pii_fields' => $this->piiFields,
        ];
    }
}
