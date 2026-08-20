<?php

namespace App\Integrations\CoreBanking;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\RestConnector;

/**
 * Temenos T24 / Transact (12.2, priority 2) — four Nigerian banks and the
 * CBN itself.
 *
 * UNTESTED TRANSPORT. Mapping written from Temenos IRIS/OFS integration
 * documentation. T24's native shape is OFS over IRIS or the Transact REST
 * APIs; both are modelled here as REST with an enquiry name per dataset.
 * verified = false.
 *
 * connection_config: base_url (IRIS gateway), company (T24 company code)
 */
class T24Connector extends RestConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 't24',
            label: 'Temenos T24 / Transact',
            transport: 'rest_api',
            authTypes: ['basic', 'oauth2'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['stmt_entries', 'users', 'user_profiles', 'override_log', 'dormant_accounts'],
            fieldMappings: [
                'stmt_entries' => [
                    'transactionReference' => 'transaction_id',
                    'bookingDate' => 'posted_at',
                    'amountLcy' => 'amount',
                    'inputter' => 'maker',
                    'authoriser' => 'checker',
                    'company' => 'branch_code',
                ],
                'users' => [
                    'signOnName' => 'subject_identifier',
                    'userName' => 'subject_name',
                    'status' => 'status',
                ],
                'user_profiles' => [
                    'signOnName' => 'subject_identifier',
                    'applicationName' => 'function',
                ],
            ],
            notCovered: [
                'OFS message construction is not implemented — enquiries only.',
                'Temenos Infinity (digital channel) is a separate product and source.',
                'Multi-company consolidation must be configured one source per company.',
            ],
            documentation: 'Expose the named enquiries through IRIS and register one dataset per enquiry. Unverified.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $company = (string) ($this->config('company') ?: 'NG0010001');

        $enquiry = fn (string $name, array $extra = []) => [
            'endpoint' => '/api/v1.0.0/enquiry/'.$name,
            'query' => array_merge(['company' => $company], $extra),
            'data_path' => 'body',
            'pagination' => ['style' => 'page', 'param' => 'page', 'size_param' => 'pageSize', 'size' => 500],
            'incremental_param' => 'fromDate',
        ];

        return [
            [
                'key' => 'stmt_entries',
                'name' => 'Statement entries',
                'primary_key_fields' => ['transactionReference'],
                'incremental_field' => 'bookingDate',
                'extraction_config' => $enquiry('STMT.ENT.BOOK'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'users',
                'name' => 'T24 users',
                'primary_key_fields' => ['signOnName'],
                'extraction_config' => $enquiry('USER.LIST'),
                'pii_classification' => 'personal',
                'pii_fields' => ['userName'],
            ],
            [
                'key' => 'user_profiles',
                'name' => 'User application profiles',
                'description' => 'Feeds the SoD engine.',
                'primary_key_fields' => ['signOnName', 'applicationName'],
                'extraction_config' => $enquiry('USER.SMS.LIST'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'override_log',
                'name' => 'Overrides accepted',
                'primary_key_fields' => ['transactionReference', 'overrideCode'],
                'incremental_field' => 'bookingDate',
                'extraction_config' => $enquiry('OVERRIDE.LIST'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'dormant_accounts',
                'name' => 'Dormant accounts',
                'primary_key_fields' => ['accountNumber'],
                'extraction_config' => $enquiry('ACCT.DORMANT'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['accountNumber', 'customerName'],
            ],
        ];
    }
}
