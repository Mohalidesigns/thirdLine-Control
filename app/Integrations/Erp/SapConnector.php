<?php

namespace App\Integrations\Erp;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\RestConnector;

/**
 * SAP via OData (12.2, priority 2).
 *
 * UNTESTED TRANSPORT. Mapping written from the standard S/4HANA OData
 * service names. RFC is deliberately not implemented: it needs a native
 * extension this platform will not ship. Where a client only exposes RFC,
 * they front it with an OData or REST gateway. verified = false.
 *
 * connection_config: base_url (…/sap/opu/odata/sap), client (mandt)
 */
class SapConnector extends RestConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'sap',
            label: 'SAP S/4HANA (OData)',
            transport: 'odata',
            authTypes: ['basic', 'oauth2', 'certificate'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['journal_entries', 'vendor_master', 'purchase_orders', 'user_roles', 'payment_runs'],
            fieldMappings: [
                'journal_entries' => [
                    'AccountingDocument' => 'transaction_id',
                    'PostingDate' => 'posted_at',
                    'AmountInCompanyCodeCurrency' => 'amount',
                    'CreatedByUser' => 'maker',
                    'CompanyCode' => 'entity',
                ],
                'user_roles' => [
                    'UserID' => 'subject_identifier',
                    'RoleName' => 'function',
                ],
                'vendor_master' => [
                    'Supplier' => 'vendor_id',
                    'SupplierName' => 'vendor_name',
                    'BankAccount' => 'bank_account',
                    'LastChangeDateTime' => 'changed_at',
                ],
            ],
            notCovered: [
                'RFC/BAPI transport — expose an OData or REST gateway instead.',
                'SAP GRC Access Control rulesets are not imported; SoD pairs are defined in SecondLine.',
                'IDoc and batch-input monitoring.',
            ],
            documentation: 'Activate the listed OData services and grant a display-only role. Unverified.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $service = fn (string $path, string $entity) => [
            'endpoint' => "/{$path}/{$entity}",
            'query' => array_filter(['sap-client' => $this->config('client'), '$format' => 'json', '$top' => 1000]),
            'data_path' => 'd.results',
            'pagination' => ['style' => 'offset', 'param' => '$skip', 'size_param' => '$top', 'size' => 1000],
            'incremental_param' => '$filter',
        ];

        return [
            [
                'key' => 'journal_entries',
                'name' => 'Journal entries',
                'primary_key_fields' => ['AccountingDocument', 'CompanyCode', 'FiscalYear'],
                'incremental_field' => 'PostingDate',
                'extraction_config' => $service('API_JOURNALENTRYITEMBASIC_SRV', 'A_JournalEntryItemBasic'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'vendor_master',
                'name' => 'Vendor master with bank details',
                'primary_key_fields' => ['Supplier'],
                'incremental_field' => 'LastChangeDateTime',
                'extraction_config' => $service('API_BUSINESS_PARTNER', 'A_Supplier'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['BankAccount', 'TaxNumber1'],
            ],
            [
                'key' => 'purchase_orders',
                'name' => 'Purchase orders',
                'primary_key_fields' => ['PurchaseOrder'],
                'incremental_field' => 'CreationDate',
                'extraction_config' => $service('API_PURCHASEORDER_PROCESS_SRV', 'A_PurchaseOrder'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'user_roles',
                'name' => 'User role assignments',
                'description' => 'Feeds the SoD engine.',
                'primary_key_fields' => ['UserID', 'RoleName'],
                'extraction_config' => $service('API_USER_SRV', 'UserRoleAssignment'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'payment_runs',
                'name' => 'Automatic payment runs',
                'primary_key_fields' => ['PaymentRunID'],
                'incremental_field' => 'PaymentRunDate',
                'extraction_config' => $service('API_PAYMENTRUN_SRV', 'A_PaymentRun'),
                'pii_classification' => 'internal',
            ],
        ];
    }
}
