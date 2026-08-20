<?php

namespace App\Integrations\Generic;

use App\Integrations\ConnectorException;
use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\Connector;
use App\Integrations\Contracts\DatasetDescriptor;
use App\Models\DataSourceDataset;
use App\Support\SqlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generic read-only SQL connector (12.2, priority 1) for MySQL,
 * PostgreSQL, SQL Server and Oracle.
 *
 * Three things make it read-only rather than politely-read-only:
 * the connection is opened with a credential the client is expected to
 * provision as a read-only account, every dataset query goes through
 * SqlGuard, and the statement is executed with a driver-level timeout and
 * a hard row cap. A dataset may also name a table + columns instead of a
 * query, in which case the SQL is generated and nothing user-written is
 * executed at all.
 *
 * connection_config:
 *   driver     mysql|pgsql|sqlsrv|oracle
 *   host, port, database, charset
 *   options    { … }        driver options
 *
 * extraction_config:
 *   table      gl_postings            (preferred — generated SQL)
 *   columns    ["id","amount", …]
 *   query      SELECT …               (validated by SqlGuard)
 *   params     { "branch": "001" }
 *   max_rows   250000
 */
class SqlConnector extends Connector
{
    public const DEFAULT_MAX_ROWS = 200000;

    private const DRIVERS = ['mysql' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433, 'oracle' => 1521];

    private ?string $connectionName = null;

    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'custom',
            label: 'Generic SQL (MySQL / PostgreSQL / SQL Server / Oracle)',
            transport: 'database',
            authTypes: ['basic'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: true,
            notCovered: [
                'Writes of any kind — the connector refuses anything that is not a SELECT.',
                'Stored procedures and multi-statement batches.',
                'Cross-database and system-catalogue queries.',
            ],
            documentation: 'Provision a read-only database account. Prefer table + columns over a hand-written query.',
        );
    }

    public function authenticate(): void
    {
        if ($this->connectionName !== null) {
            return;
        }

        $driver = (string) $this->config('driver', 'mysql');

        if (! array_key_exists($driver, self::DRIVERS)) {
            throw new ConnectorException("'{$driver}' is not a supported database driver.");
        }

        $bag = $this->credentialBag();
        $name = 'ccm_source_'.$this->source->id;

        config(["database.connections.{$name}" => [
            'driver' => $driver === 'oracle' ? 'oci' : $driver,
            'host' => (string) $this->config('host'),
            'port' => (int) ($this->config('port') ?? self::DRIVERS[$driver]),
            'database' => (string) $this->config('database'),
            'username' => (string) ($bag['username'] ?? ''),
            'password' => (string) ($bag['password'] ?? ''),
            'charset' => (string) ($this->config('charset') ?? 'utf8mb4'),
            'options' => (array) ($this->config('options') ?? []),
        ]]);

        $this->connectionName = $name;
    }

    public function testConnection(): array
    {
        $started = microtime(true);

        try {
            $this->authenticate();
            DB::connection($this->connectionName)->select('select 1');

            return ['ok' => true, 'message' => 'Connected.', 'latency_ms' => (int) ((microtime(true) - $started) * 1000)];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => ConnectorException::redactMessage($e->getMessage()),
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } finally {
            $this->disconnect();
        }
    }

    public function listDatasets(): array
    {
        // Catalogue introspection would mean querying a system table,
        // which this connector is not allowed to do. Datasets are declared.
        return array_map(
            fn (array $d) => new DatasetDescriptor(
                key: $d['key'],
                name: $d['name'],
                description: $d['description'] ?? null,
                primaryKeyFields: $d['primary_key_fields'] ?? [],
                schema: $d['schema'] ?? [],
                extractionConfig: $d['extraction_config'] ?? [],
                incrementalField: $d['incremental_field'] ?? null,
                piiClassification: $d['pii_classification'] ?? 'none',
                piiFields: $d['pii_fields'] ?? [],
            ),
            $this->datasetTemplates(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        return [];
    }

    public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable
    {
        $this->authenticate();

        $config = $dataset->extraction_config ?? [];
        $maxRows = (int) ($config['max_rows'] ?? self::DEFAULT_MAX_ROWS);
        [$sql, $bindings] = $this->buildQuery($dataset, $since);

        try {
            $connection = DB::connection($this->connectionName);
            $rows = 0;

            foreach ($connection->cursor($sql, $bindings) as $row) {
                if ($rows++ >= $maxRows) {
                    return;
                }

                yield (array) $row;
            }
        } catch (\Throwable $e) {
            throw ConnectorException::from($e);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildQuery(DataSourceDataset $dataset, ?CarbonImmutable $since): array
    {
        $config = $dataset->extraction_config ?? [];
        $bindings = (array) ($config['params'] ?? []);

        if (! empty($config['query'])) {
            // A hand-written query is validated the same way custom_sql is,
            // against the table this dataset is allowed to read.
            $tables = array_filter([$config['table'] ?? null]);

            try {
                SqlGuard::assertReadOnly((string) $config['query'], $tables, array_keys($bindings));
            } catch (\InvalidArgumentException $e) {
                throw new ConnectorException('Dataset query rejected: '.$e->getMessage());
            }

            return [(string) $config['query'], $bindings];
        }

        $table = (string) ($config['table'] ?? '');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new ConnectorException('The dataset must name a plain table or supply a validated query.');
        }

        $columns = array_values(array_filter(
            (array) ($config['columns'] ?? ['*']),
            fn ($c) => $c === '*' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $c),
        ));

        $sql = 'select '.implode(', ', $columns ?: ['*'])." from {$table}";

        if ($since && $dataset->incremental_field
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $dataset->incremental_field)) {
            $sql .= " where {$dataset->incremental_field} >= :since";
            $bindings['since'] = $since->toDateTimeString();
        }

        return [$sql, $bindings];
    }

    private function disconnect(): void
    {
        if ($this->connectionName !== null) {
            DB::purge($this->connectionName);
        }
    }
}
