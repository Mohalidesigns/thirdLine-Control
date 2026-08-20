<?php

namespace App\Integrations\Erp;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\RestConnector;

/**
 * Sage 300 / X3 / Evolution (12.2, priority 2) — the mid-market ERP most
 * commonly found behind Nigerian and South African finance functions.
 *
 * UNTESTED TRANSPORT. Sage's three product lines expose three different
 * APIs; this connector models the REST-ish shape common to Sage 300 Web
 * API and X3's representation service, with the product selected in
 * configuration. Evolution is ODBC in practice — register it as a generic
 * SQL source instead. verified = false.
 *
 * connection_config: base_url, product (300|x3), company
 */
class SageConnector extends RestConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'sage',
            label: 'Sage 300 / X3',
            transport: 'rest_api',
            authTypes: ['basic', 'oauth2'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['gl_transactions', 'ap_invoices', 'vendors', 'users'],
            fieldMappings: [
                'gl_transactions' => [
                    'PostingSequence' => 'transaction_id',
                    'FiscalPeriod' => 'period',
                    'Amount' => 'amount',
                    'EnteredBy' => 'maker',
                ],
                'ap_invoices' => [
                    'DocumentNumber' => 'transaction_id',
                    'VendorNumber' => 'vendor_id',
                    'DocumentTotal' => 'amount',
                    'DocumentDate' => 'posted_at',
                ],
                'vendors' => [
                    'VendorNumber' => 'vendor_id',
                    'VendorName' => 'vendor_name',
                    'BankAccountNumber' => 'bank_account',
                ],
            ],
            notCovered: [
                'Sage Evolution — use the generic SQL connector over ODBC.',
                'Payroll modules are excluded; treat payroll as its own HRMS source.',
                'Sage Intacct is a different product and is not mapped.',
            ],
            documentation: 'Sage 300 Web API or X3 representation service, read-only user. Unverified.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $product = (string) ($this->config('product') ?: '300');
        $company = (string) ($this->config('company') ?: '');
        $prefix = $product === 'x3' ? '/api1/x3/erp' : '/v1.0/-/'.$company;

        $endpoint = fn (string $path, string $dataPath = 'value') => [
            'endpoint' => $prefix.$path,
            'query' => [],
            'data_path' => $dataPath,
            'pagination' => ['style' => 'offset', 'param' => '$skip', 'size_param' => '$top', 'size' => 500],
            'incremental_param' => '$filter',
        ];

        return [
            [
                'key' => 'gl_transactions',
                'name' => 'General ledger transactions',
                'primary_key_fields' => ['PostingSequence', 'BatchNumber', 'EntryNumber'],
                'incremental_field' => 'DateEntered',
                'extraction_config' => $endpoint('/GL/GLPostedTransactions'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'ap_invoices',
                'name' => 'Accounts-payable invoices',
                'primary_key_fields' => ['DocumentNumber'],
                'incremental_field' => 'DocumentDate',
                'extraction_config' => $endpoint('/AP/APInvoices'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'vendors',
                'name' => 'Vendor master with bank details',
                'primary_key_fields' => ['VendorNumber'],
                'incremental_field' => 'LastMaintained',
                'extraction_config' => $endpoint('/AP/APVendors'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['BankAccountNumber', 'TaxNumber'],
            ],
            [
                'key' => 'users',
                'name' => 'Application users and rights',
                'primary_key_fields' => ['UserID'],
                'extraction_config' => $endpoint('/AS/Users'),
                'pii_classification' => 'personal',
                'pii_fields' => ['UserName'],
            ],
        ];
    }
}
