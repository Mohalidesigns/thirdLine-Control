<?php

namespace App\Integrations\Erp;

use App\Integrations\ConnectorException;
use App\Integrations\Contracts\Capability;
use App\Integrations\Contracts\DatasetDescriptor;
use App\Integrations\Microsoft\GraphConnector;
use App\Models\DataSourceDataset;
use Carbon\CarbonImmutable;

/**
 * Microsoft Dynamics 365 via Dataverse (12.2, priority 2).
 *
 * UNTESTED TRANSPORT. Dataverse speaks OData with the same Entra
 * client-credentials flow as Graph, so the token handling is inherited
 * and only the resource and entity names differ. verified = false.
 *
 * connection_config: tenant_id, base_url (https://org.crm4.dynamics.com/api/data/v9.2)
 * credentials (JSON): { "client_id": "…", "client_secret": "…" }
 */
class Dynamics365Connector extends GraphConnector
{
    private const ENTITIES = [
        'accounts' => ['name' => 'Accounts', 'entity' => 'accounts', 'pk' => 'accountid', 'pii' => 'personal'],
        'contacts' => ['name' => 'Contacts', 'entity' => 'contacts', 'pk' => 'contactid', 'pii' => 'sensitive_personal'],
        'invoices' => ['name' => 'Invoices', 'entity' => 'invoices', 'pk' => 'invoiceid', 'pii' => 'internal'],
        'system_users' => ['name' => 'System users', 'entity' => 'systemusers', 'pk' => 'systemuserid', 'pii' => 'personal'],
        'security_roles' => ['name' => 'Security role assignments', 'entity' => 'systemuserroles_association', 'pk' => 'systemuserid', 'pii' => 'internal'],
        'audit_log' => ['name' => 'Dataverse audit log', 'entity' => 'audits', 'pk' => 'auditid', 'pii' => 'internal'],
    ];

    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'dynamics365',
            label: 'Microsoft Dynamics 365 (Dataverse)',
            transport: 'odata',
            authTypes: ['oauth2'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: array_keys(self::ENTITIES),
            fieldMappings: [
                'system_users' => [
                    'domainname' => 'subject_identifier',
                    'fullname' => 'subject_name',
                    'isdisabled' => 'is_disabled',
                ],
                'security_roles' => [
                    'domainname' => 'subject_identifier',
                    'roleid' => 'function',
                ],
                'invoices' => [
                    'invoicenumber' => 'transaction_id',
                    'totalamount' => 'amount',
                    'createdon' => 'posted_at',
                    'createdby' => 'maker',
                ],
            ],
            notCovered: [
                'Custom entities must be added as datasets by hand.',
                'Business-unit hierarchy is not resolved into SecondLine organisation units automatically.',
            ],
            documentation: 'Register an Entra app with Dataverse user impersonation and a read-only security role.',
        );
    }

    public function listDatasets(): array
    {
        return array_map(
            fn (string $key) => new DatasetDescriptor(
                key: $key,
                name: self::ENTITIES[$key]['name'],
                description: 'Dataverse entity set '.self::ENTITIES[$key]['entity'],
                primaryKeyFields: [self::ENTITIES[$key]['pk']],
                extractionConfig: [
                    'endpoint' => '/'.self::ENTITIES[$key]['entity'],
                    'query' => ['$top' => 500],
                    'data_path' => 'value',
                    'pagination' => ['style' => 'graph_next', 'cursor_path' => '@odata.nextLink'],
                ],
                incrementalField: 'modifiedon',
                piiClassification: self::ENTITIES[$key]['pii'],
            ),
            array_keys(self::ENTITIES),
        );
    }

    public function extract(DataSourceDataset $dataset, ?CarbonImmutable $since = null): iterable
    {
        // Dataverse paginates exactly as Graph does, so the parent loop is
        // correct as it stands.
        yield from parent::extract($dataset, $since);
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) $this->config('base_url'), '/');

        if ($base === '') {
            throw new ConnectorException('Dataverse requires the organisation base_url.');
        }

        return $base.'/'.ltrim($path, '/');
    }
}
