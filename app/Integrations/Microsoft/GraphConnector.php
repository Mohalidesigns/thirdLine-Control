<?php

namespace App\Integrations\Microsoft;

use App\Integrations\ConnectorException;
use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\DatasetDescriptor;
use App\Integrations\Generic\RestConnector;
use App\Models\DataSourceDataset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft 365 / Entra ID via the Graph API (12.2, priority 1).
 *
 * This is the connector that makes access controls and SoD testable
 * without a core-banking integration: who exists, who is privileged, who
 * has MFA, and who signed in from where. Every dataset here is read-only
 * and needs only application permissions of the *.Read.All family.
 *
 * connection_config:
 *   tenant_id      the Entra directory id
 *   base_url       https://graph.microsoft.com/v1.0   (default)
 *   auth.token_url derived from tenant_id when absent
 * credentials (JSON): { "client_id": "…", "client_secret": "…" }
 */
class GraphConnector extends RestConnector
{
    public const DATASETS = [
        'users' => [
            'name' => 'Directory users',
            'endpoint' => '/users',
            'select' => 'id,displayName,userPrincipalName,accountEnabled,department,jobTitle,createdDateTime,signInActivity',
            'pk' => ['id'],
            'pii' => 'personal',
            'pii_fields' => ['displayName', 'userPrincipalName'],
        ],
        'groups' => [
            'name' => 'Groups',
            'endpoint' => '/groups',
            'select' => 'id,displayName,securityEnabled,createdDateTime',
            'pk' => ['id'],
            'pii' => 'internal',
            'pii_fields' => [],
        ],
        'group_members' => [
            'name' => 'Group membership',
            'endpoint' => '/groups',
            'select' => 'id,displayName',
            'pk' => ['group_id', 'member_id'],
            'pii' => 'personal',
            'pii_fields' => ['member_upn'],
        ],
        'directory_roles' => [
            'name' => 'Privileged directory roles',
            'endpoint' => '/directoryRoles',
            'select' => 'id,displayName,roleTemplateId',
            'pk' => ['id'],
            'pii' => 'internal',
            'pii_fields' => [],
        ],
        'role_assignments' => [
            'name' => 'Privileged role assignments',
            'endpoint' => '/directoryRoles',
            'select' => 'id,displayName',
            'pk' => ['role_id', 'member_id'],
            'pii' => 'personal',
            'pii_fields' => ['member_upn'],
        ],
        'mfa_registration' => [
            'name' => 'MFA registration status',
            'endpoint' => '/reports/authenticationMethods/userRegistrationDetails',
            'select' => 'id,userPrincipalName,isMfaRegistered,isMfaCapable,isAdmin',
            'pk' => ['id'],
            'pii' => 'personal',
            'pii_fields' => ['userPrincipalName'],
        ],
        'sign_in_logs' => [
            'name' => 'Sign-in logs',
            'endpoint' => '/auditLogs/signIns',
            'select' => 'id,createdDateTime,userPrincipalName,appDisplayName,ipAddress,status,location',
            'pk' => ['id'],
            'pii' => 'personal',
            'pii_fields' => ['userPrincipalName', 'ipAddress'],
            'incremental' => 'createdDateTime',
        ],
    ];

    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'entra',
            label: 'Microsoft 365 / Entra ID (Graph)',
            transport: 'rest_api',
            authTypes: ['oauth2'],
            supportsIncremental: true,
            supportsDiscovery: true,
            supportsPagination: true,
            verified: true,
            datasets: array_keys(self::DATASETS),
            fieldMappings: [
                'users' => [
                    'userPrincipalName' => 'subject_identifier',
                    'displayName' => 'subject_name',
                    'accountEnabled' => 'is_active',
                    'department' => 'entity',
                ],
                'role_assignments' => [
                    'member_upn' => 'subject_identifier',
                    'role_name' => 'function',
                ],
            ],
            notCovered: [
                'Exchange, SharePoint and Teams content — this connector reads identity only.',
                'Conditional Access policy evaluation; policies are readable but not simulated.',
                'Anything requiring delegated (user) consent — application permissions only.',
            ],
            documentation: 'Register an Entra application with Directory.Read.All, AuditLog.Read.All and '
                .'UserAuthenticationMethod.Read.All, then store client_id/client_secret as JSON credentials.',
        );
    }

    public function authenticate(): void
    {
        if ($this->bearerToken !== null) {
            return;
        }

        $bag = $this->credentialBag();
        $tenant = (string) $this->config('tenant_id');
        $tokenUrl = (string) ($this->config('auth.token_url')
            ?: "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token");

        if ($tenant === '' && ! $this->config('auth.token_url')) {
            throw new ConnectorException('Graph requires a tenant_id (or an explicit auth.token_url).');
        }

        try {
            $response = Http::timeout($this->timeout())->asForm()->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $bag['client_id'] ?? '',
                'client_secret' => $bag['client_secret'] ?? '',
                'scope' => (string) ($this->config('auth.scope') ?: 'https://graph.microsoft.com/.default'),
            ]);
        } catch (\Throwable $e) {
            throw ConnectorException::from($e, 'Entra token request failed');
        }

        if (! $response->successful()) {
            throw new ConnectorException('Entra rejected the token request with HTTP '.$response->status().'.');
        }

        $this->bearerToken = $response->json('access_token');

        if (! $this->bearerToken) {
            throw new ConnectorException('Entra returned no access_token.');
        }
    }

    public function listDatasets(): array
    {
        return array_map(
            fn (string $key) => new DatasetDescriptor(
                key: $key,
                name: self::DATASETS[$key]['name'],
                description: 'Microsoft Graph '.self::DATASETS[$key]['endpoint'],
                primaryKeyFields: self::DATASETS[$key]['pk'],
                extractionConfig: [
                    'endpoint' => self::DATASETS[$key]['endpoint'],
                    'query' => ['$select' => self::DATASETS[$key]['select'], '$top' => 200],
                    'data_path' => 'value',
                    'pagination' => ['style' => 'graph_next', 'cursor_path' => '@odata.nextLink'],
                    'incremental_param' => isset(self::DATASETS[$key]['incremental']) ? '$filter' : null,
                ],
                incrementalField: self::DATASETS[$key]['incremental'] ?? null,
                piiClassification: self::DATASETS[$key]['pii'],
                piiFields: self::DATASETS[$key]['pii_fields'],
            ),
            array_keys(self::DATASETS),
        );
    }

    /**
     * Graph paginates with an absolute @odata.nextLink rather than a page
     * number, so the loop follows links instead of incrementing.
     */
    public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable
    {
        $this->authenticate();

        $config = $dataset->extraction_config ?? [];
        $query = (array) ($config['query'] ?? []);

        if ($since && $dataset->incremental_field) {
            $query['$filter'] = sprintf('%s ge %s', $dataset->incremental_field, $since->toIso8601ZuluString());
        }

        $next = $this->url((string) ($config['endpoint'] ?? '/users'));
        $maxPages = (int) ($config['pagination']['max_pages'] ?? 500);

        for ($page = 0; $page < $maxPages && $next !== null; $page++) {
            try {
                $response = $this->client()->get($next, $page === 0 ? $query : []);
            } catch (\Throwable $e) {
                throw ConnectorException::from($e);
            }

            if ($response->status() === 429) {
                throw new ConnectorException('Graph throttled the extraction (HTTP 429); lower the rate limit.');
            }

            if (! $response->successful()) {
                throw new ConnectorException('Graph returned HTTP '.$response->status().'.');
            }

            foreach ((array) $response->json('value', []) as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            $next = $response->json('@odata.nextLink');
        }
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) ($this->config('base_url') ?: 'https://graph.microsoft.com/v1.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
