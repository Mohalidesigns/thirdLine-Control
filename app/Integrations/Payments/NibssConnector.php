<?php

namespace App\Integrations\Payments;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\RestConnector;

/**
 * NIBSS (12.2, priority 2) — NIP, BVN validation, GSI and NQR.
 *
 * UNTESTED TRANSPORT. Mapping written from NIBSS integration material.
 * NIBSS access is contractual and per-institution; nothing here can be
 * exercised without a signed connection, so verified = false.
 *
 * DATA PROTECTION: every NIBSS dataset touches BVN or account numbers,
 * which are sensitive personal data under the NDPA. Each template below
 * declares pii_classification = sensitive_personal, so SnapshotService
 * hashes those fields at ingestion unless the tenant's DPO has recorded
 * an explicit authorisation (12.7, R5).
 *
 * connection_config: base_url, institution_code, channel_code
 * credentials (JSON): { "client_id": "…", "client_secret": "…" }
 */
class NibssConnector extends RestConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'nibss',
            label: 'NIBSS (NIP, BVN, GSI, NQR)',
            transport: 'rest_api',
            authTypes: ['oauth2', 'api_key'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['nip_outward', 'nip_inward', 'bvn_watchlist', 'gsi_triggers', 'nqr_settlements'],
            fieldMappings: [
                'nip_outward' => [
                    'sessionId' => 'transaction_id',
                    'transactionDate' => 'posted_at',
                    'amount' => 'amount',
                    'beneficiaryAccountNumber' => 'beneficiary_account',
                    'responseCode' => 'status_code',
                ],
                'bvn_watchlist' => [
                    'bvn' => 'subject_identifier',
                    'watchListedReason' => 'reason',
                    'dateAdded' => 'detected_at',
                ],
                'gsi_triggers' => [
                    'bvn' => 'subject_identifier',
                    'loanAccountNumber' => 'loan_id',
                    'amountRecovered' => 'amount',
                ],
            ],
            notCovered: [
                'Direct debit mandates and cheque truncation are separate NIBSS services.',
                'No settlement-position reconciliation — pair with the bank’s own nostro extract.',
                'BVN *lookup* is deliberately not exposed: this connector reads control data, not customers.',
            ],
            documentation: 'Requires an executed NIBSS connection agreement and institution credentials. '
                .'All datasets are sensitive personal data by default.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $institution = (string) ($this->config('institution_code') ?: '');

        $endpoint = fn (string $path) => [
            'endpoint' => $path,
            'query' => array_filter(['institutionCode' => $institution]),
            'data_path' => 'data',
            'pagination' => ['style' => 'page', 'param' => 'page', 'size_param' => 'size', 'size' => 500],
            'incremental_param' => 'startDate',
        ];

        return [
            [
                'key' => 'nip_outward',
                'name' => 'NIP outward transfers',
                'primary_key_fields' => ['sessionId'],
                'incremental_field' => 'transactionDate',
                'extraction_config' => $endpoint('/nip/v2/outward'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['beneficiaryAccountNumber', 'beneficiaryName', 'originatorAccountNumber'],
            ],
            [
                'key' => 'nip_inward',
                'name' => 'NIP inward transfers',
                'primary_key_fields' => ['sessionId'],
                'incremental_field' => 'transactionDate',
                'extraction_config' => $endpoint('/nip/v2/inward'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['beneficiaryAccountNumber', 'originatorAccountNumber'],
            ],
            [
                'key' => 'bvn_watchlist',
                'name' => 'BVN watch-list entries',
                'primary_key_fields' => ['bvn'],
                'extraction_config' => $endpoint('/bvn/v2/watchlist'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['bvn'],
            ],
            [
                'key' => 'gsi_triggers',
                'name' => 'Global Standing Instruction recoveries',
                'primary_key_fields' => ['bvn', 'loanAccountNumber'],
                'incremental_field' => 'triggerDate',
                'extraction_config' => $endpoint('/gsi/v1/recoveries'),
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['bvn', 'loanAccountNumber'],
            ],
            [
                'key' => 'nqr_settlements',
                'name' => 'NQR merchant settlements',
                'primary_key_fields' => ['settlementId'],
                'incremental_field' => 'settlementDate',
                'extraction_config' => $endpoint('/nqr/v1/settlements'),
                'pii_classification' => 'personal',
                'pii_fields' => ['merchantAccountNumber'],
            ],
        ];
    }
}
