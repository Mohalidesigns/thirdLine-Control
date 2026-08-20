<?php

namespace App\Integrations\CoreBanking;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\RestConnector;

/**
 * Appzone BankOne (12.2, priority 2) — the microfinance-bank and OFI
 * tier, where the control function is usually one or two people and
 * automation matters most.
 *
 * UNTESTED TRANSPORT. Mapping written from BankOne's published partner
 * API shape (versioned REST, institution code + auth token). Unverified.
 *
 * connection_config: base_url, institution_code, version (default 2)
 * credentials (JSON): { "secret": "<auth token>" }
 */
class BankOneConnector extends RestConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'bankone',
            label: 'Appzone BankOne (MFB / OFI)',
            transport: 'rest_api',
            authTypes: ['api_key'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['transactions', 'accounts', 'users', 'loans'],
            fieldMappings: [
                'transactions' => [
                    'TransactionReference' => 'transaction_id',
                    'TransactionDate' => 'posted_at',
                    'Amount' => 'amount',
                    'InitiatedBy' => 'maker',
                    'ApprovedBy' => 'checker',
                    'BranchCode' => 'branch_code',
                ],
                'accounts' => [
                    'NUBAN' => 'account_number',
                    'CustomerName' => 'customer_name',
                    'AccountStatus' => 'status',
                ],
                'loans' => [
                    'LoanAccountNumber' => 'loan_id',
                    'ClassificationStatus' => 'classification',
                    'DaysPastDue' => 'dpd',
                ],
            ],
            notCovered: [
                'Agency-banking and wallet products are separate BankOne endpoints.',
                'No user-entitlement endpoint is published, so SoD on BankOne needs a file extract.',
            ],
            documentation: 'Request read scopes from Appzone for the institution code. All mappings unverified.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $code = (string) ($this->config('institution_code') ?: '');
        $version = (string) ($this->config('version') ?: '2');

        $endpoint = fn (string $path) => [
            'endpoint' => "/BankOneWebAPI/api/{$path}/{$version}",
            'query' => array_filter(['institutionCode' => $code]),
            'data_path' => 'Data',
            'pagination' => ['style' => 'page', 'param' => 'pageNumber', 'size_param' => 'pageSize', 'size' => 500],
            'incremental_param' => 'fromDate',
        ];

        return [
            [
                'key' => 'transactions',
                'name' => 'Posted transactions',
                'primary_key_fields' => ['TransactionReference'],
                'incremental_field' => 'TransactionDate',
                'extraction_config' => $endpoint('Transaction/GetTransactions'),
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'accounts',
                'name' => 'Customer accounts',
                'primary_key_fields' => ['NUBAN'],
                'extraction_config' => $endpoint('Account/GetAccounts'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['NUBAN', 'CustomerName', 'BVN'],
            ],
            [
                'key' => 'users',
                'name' => 'Institution users',
                'primary_key_fields' => ['UserName'],
                'extraction_config' => $endpoint('User/GetUsers'),
                'pii_classification' => 'personal',
                'pii_fields' => ['FullName'],
            ],
            [
                'key' => 'loans',
                'name' => 'Loan portfolio and classification',
                'primary_key_fields' => ['LoanAccountNumber'],
                'extraction_config' => $endpoint('Loan/GetLoans'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['LoanAccountNumber', 'CustomerName'],
            ],
        ];
    }
}
