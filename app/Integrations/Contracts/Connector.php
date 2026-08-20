<?php

namespace App\Integrations\Contracts;

use App\Integrations\ConnectorException;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use Carbon\CarbonImmutable;

/**
 * The connector contract (12.1). One class per vendor, all of them
 * reduced to the same four verbs so the rest of the platform never knows
 * whether a row came from Finacle, a CSV on an SFTP box, or Graph.
 *
 * Implementations must never log, echo, or return credentials, and must
 * never write to the source system: every connector is read-only by
 * construction.
 */
abstract class Connector
{
    public function __construct(protected DataSource $source) {}

    /** Static so the registry can describe a vendor without a configured source. */
    abstract public static function capabilities(): Capability;

    /**
     * Acquire whatever the transport needs (a token, a session, a
     * handle). Called once before extraction; safe to call twice.
     *
     * @throws ConnectorException
     */
    abstract public function authenticate(): void;

    /**
     * A cheap round trip proving the configuration works.
     *
     * @return array{ok: bool, message: string, latency_ms: int}
     */
    abstract public function testConnection(): array;

    /**
     * Datasets the source exposes. Connectors that cannot introspect
     * return their documented mapping template instead.
     *
     * @return array<int, DatasetDescriptor>
     */
    abstract public function listDatasets(): array;

    /**
     * Extract rows, newest-first not required. Implementations should
     * yield rather than build an array — a core-banking extract does not
     * fit in memory (R6).
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws ConnectorException
     */
    abstract public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable;

    public function dataSource(): DataSource
    {
        return $this->source;
    }

    /** Decoded, never-serialised connection settings. */
    protected function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->source->connection_config, $key, $default);
    }

    protected function credentials(): ?string
    {
        return $this->source->credentials;
    }

    /**
     * Credentials are stored as a single encrypted string; where a vendor
     * needs several (client id + secret, user + password) the string is
     * JSON. Callers get an array either way.
     *
     * @return array<string, mixed>
     */
    protected function credentialBag(): array
    {
        $raw = $this->credentials();

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['secret' => $raw];
    }

    protected function timeout(): int
    {
        return max(1, (int) $this->source->timeout_seconds);
    }
}
