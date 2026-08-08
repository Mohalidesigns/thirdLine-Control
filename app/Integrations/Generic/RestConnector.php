<?php

namespace App\Integrations\Generic;

use App\Integrations\ConnectorException;
use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\Connector;
use App\Integrations\Contracts\DatasetDescriptor;
use App\Models\DataSourceDataset;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Generic REST/JSON connector (12.2, priority 1).
 *
 * Everything is configuration: base URL, auth style, the endpoint per
 * dataset, the JSONPath-ish dot path to the row array, and the pagination
 * shape. This is the connector most in-house and fintech systems need,
 * and the base class for the vendor REST connectors.
 *
 * connection_config:
 *   base_url            https://core.bank.internal/api
 *   headers             { "X-Institution": "058" }
 *   auth.header         X-Api-Key            (auth_type = api_key)
 *   auth.token_url      …/oauth2/token       (auth_type = oauth2)
 *   auth.scope          …
 *   verify              true|false (TLS verification; false must be justified)
 *
 * extraction_config (per dataset):
 *   endpoint            /v1/transactions
 *   method              GET|POST
 *   query               { "branch": "001" }
 *   data_path           data.items          (dot path to the row array)
 *   pagination.style    page|cursor|offset|none
 *   pagination.param    page
 *   pagination.size_param / size
 *   pagination.cursor_path  meta.next_cursor
 *   incremental_param   updatedSince
 */
class RestConnector extends Connector
{
    protected ?string $bearerToken = null;

    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'custom',
            label: 'Generic REST / JSON',
            transport: 'rest_api',
            authTypes: ['none', 'basic', 'api_key', 'oauth2', 'jwt'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: true,
            notCovered: [
                'No schema discovery — declare the dataset schema by hand or import a sample.',
                'No write-back: the connector is read-only by construction.',
            ],
            documentation: 'Configure base_url plus one endpoint per dataset; rows are read from data_path.',
        );
    }

    public function authenticate(): void
    {
        if ($this->source->auth_type !== 'oauth2' || $this->bearerToken !== null) {
            return;
        }

        $bag = $this->credentialBag();
        $tokenUrl = (string) $this->config('auth.token_url');

        if ($tokenUrl === '') {
            throw new ConnectorException('OAuth2 is configured but auth.token_url is missing.');
        }

        try {
            $response = Http::timeout($this->timeout())
                ->asForm()
                ->post($tokenUrl, array_filter([
                    'grant_type' => 'client_credentials',
                    'client_id' => $bag['client_id'] ?? null,
                    'client_secret' => $bag['client_secret'] ?? null,
                    'scope' => $this->config('auth.scope'),
                ]));
        } catch (\Throwable $e) {
            throw ConnectorException::from($e, 'Token request failed');
        }

        if (! $response->successful()) {
            throw new ConnectorException('Token request rejected with HTTP '.$response->status().'.');
        }

        $this->bearerToken = $response->json('access_token');

        if (! $this->bearerToken) {
            throw new ConnectorException('The token endpoint returned no access_token.');
        }
    }

    public function testConnection(): array
    {
        $started = microtime(true);

        try {
            $this->authenticate();

            $path = (string) ($this->config('health_path') ?? '/');
            $response = $this->client()->get($this->url($path));

            return [
                'ok' => $response->successful(),
                'message' => $response->successful()
                    ? 'Reachable — HTTP '.$response->status().'.'
                    : 'Rejected with HTTP '.$response->status().'.',
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => ConnectorException::redactMessage($e->getMessage()),
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        }
    }

    public function listDatasets(): array
    {
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

    /**
     * Vendor subclasses override this with their documented mapping.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function datasetTemplates(): array
    {
        return [];
    }

    public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable
    {
        $this->authenticate();

        $config = $dataset->extraction_config ?? [];
        $endpoint = (string) ($config['endpoint'] ?? '/');
        $method = strtolower((string) ($config['method'] ?? 'get'));
        $dataPath = $config['data_path'] ?? null;
        $style = $config['pagination']['style'] ?? 'none';
        $pageParam = $config['pagination']['param'] ?? 'page';
        $sizeParam = $config['pagination']['size_param'] ?? 'per_page';
        $pageSize = (int) ($config['pagination']['size'] ?? 500);
        $cursorPath = $config['pagination']['cursor_path'] ?? null;
        $maxPages = (int) ($config['pagination']['max_pages'] ?? 200);

        $query = (array) ($config['query'] ?? []);

        if ($since && ($config['incremental_param'] ?? null)) {
            $query[$config['incremental_param']] = $since->toIso8601String();
        }

        $page = (int) ($config['pagination']['start'] ?? 1);
        $cursor = null;

        for ($fetched = 0; $fetched < max(1, $maxPages); $fetched++) {
            $paged = $query;

            if ($style === 'page' || $style === 'offset') {
                $paged[$pageParam] = $style === 'offset' ? ($page - 1) * $pageSize : $page;
                $paged[$sizeParam] = $pageSize;
            } elseif ($style === 'cursor' && $cursor !== null) {
                $paged[$pageParam] = $cursor;
            }

            try {
                $response = $method === 'post'
                    ? $this->client()->post($this->url($endpoint), $paged)
                    : $this->client()->get($this->url($endpoint), $paged);
            } catch (\Throwable $e) {
                throw ConnectorException::from($e);
            }

            if (! $response->successful()) {
                throw new ConnectorException('The source returned HTTP '.$response->status().'.');
            }

            $body = $response->json() ?? [];
            $rows = $dataPath ? data_get($body, $dataPath, []) : $body;

            if (! is_array($rows) || $rows === []) {
                return;
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            if ($style === 'none') {
                return;
            }

            if ($style === 'cursor') {
                $cursor = $cursorPath ? data_get($body, $cursorPath) : null;

                if (! $cursor) {
                    return;
                }
            } else {
                if (count($rows) < $pageSize) {
                    return;
                }

                $page++;
            }
        }
    }

    protected function client(): PendingRequest
    {
        $request = Http::timeout($this->timeout())
            ->retry(2, 250, throw: false)
            ->withHeaders((array) ($this->config('headers') ?? []))
            ->acceptJson();

        if ($this->config('verify') === false) {
            $request = $request->withoutVerifying();
        }

        $bag = $this->credentialBag();

        return match ($this->source->auth_type) {
            'basic' => $request->withBasicAuth((string) ($bag['username'] ?? ''), (string) ($bag['password'] ?? '')),
            'api_key' => $request->withHeaders([
                (string) ($this->config('auth.header') ?? 'X-Api-Key') => (string) ($bag['secret'] ?? $bag['api_key'] ?? ''),
            ]),
            'oauth2' => $request->withToken((string) $this->bearerToken),
            'jwt' => $request->withToken((string) ($bag['secret'] ?? $bag['token'] ?? '')),
            default => $request,
        };
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) $this->config('base_url'), '/');

        if ($base === '') {
            throw new ConnectorException('No base_url is configured for this source.');
        }

        return $base.'/'.ltrim($path, '/');
    }
}
