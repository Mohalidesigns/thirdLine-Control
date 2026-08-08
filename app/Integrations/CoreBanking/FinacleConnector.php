<?php

namespace App\Integrations\CoreBanking;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\SqlConnector;

/**
 * Infosys Finacle (12.2, priority 2) — the largest core-banking footprint
 * in Nigeria at roughly 37% of the deposit-money banks.
 *
 * UNTESTED TRANSPORT. The dataset mapping below is written from Finacle
 * data-model documentation and the shape of the standard extract tables;
 * it has not been exercised against a live installation. The connector
 * therefore ships with verified = false, which the UI shows as an explicit
 * warning on the source, and a tenant must confirm each mapping against
 * their own instance before a rule built on it is approved.
 *
 * Transport: read-only Oracle account against the Finacle schema. Where a
 * bank exposes Finacle Connect (REST) instead, register the source as a
 * generic REST source with these same field names.
 *
 * connection_config: driver=oracle, host, port, database (service name),
 *                    schema (defaults to TBAADM)
 */
class FinacleConnector extends SqlConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'finacle',
            label: 'Infosys Finacle (core banking)',
            transport: 'database',
            authTypes: ['basic'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['gl_postings', 'user_accounts', 'user_roles', 'dormant_accounts', 'limit_overrides'],
            fieldMappings: [
                'gl_postings' => [
                    'TRAN_ID' => 'transaction_id',
                    'TRAN_DATE' => 'posted_at',
                    'TRAN_AMT' => 'amount',
                    'ENTERED_BY' => 'maker',
                    'AUTH_BY' => 'checker',
                    'AUTH_DATE' => 'authorised_at',
                    'SOL_ID' => 'branch_code',
                ],
                'user_accounts' => [
                    'USER_ID' => 'subject_identifier',
                    'USER_NAME' => 'subject_name',
                    'USER_STATUS' => 'status',
                    'SOL_ID' => 'branch_code',
                    'LAST_LOGIN_DATE' => 'last_login_at',
                ],
                'user_roles' => [
                    'USER_ID' => 'subject_identifier',
                    'ROLE_ID' => 'function',
                    'MENU_ID' => 'function_detail',
                ],
                'dormant_accounts' => [
                    'FORACID' => 'account_number',
                    'ACCT_CLS_FLG' => 'closed_flag',
                    'LAST_TRAN_DATE' => 'last_activity_at',
                ],
                'limit_overrides' => [
                    'TRAN_ID' => 'transaction_id',
                    'OVERRIDE_CODE' => 'override_code',
                    'OVERRIDE_BY' => 'subject_identifier',
                ],
            ],
            notCovered: [
                'Finacle Core versions differ in table and column naming; confirm against your schema.',
                'No CRM, no treasury, no trade-finance modules.',
                'Islamic-banking (Finacle IB) tables are not mapped.',
                'Write-back and transaction reversal are out of scope by design.',
            ],
            documentation: 'Provision a read-only Oracle account with SELECT on the extract views. '
                .'Every mapping below is unverified until confirmed against the bank’s own instance.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $schema = (string) ($this->config('schema') ?: 'TBAADM');

        return [
            [
                'key' => 'gl_postings',
                'name' => 'General ledger postings',
                'description' => "Maker/checker evidence for every posting ({$schema}).",
                'primary_key_fields' => ['TRAN_ID'],
                'incremental_field' => 'TRAN_DATE',
                'extraction_config' => [
                    'table' => 'GENERAL_ACCT_MAST_TRAN',
                    'columns' => ['TRAN_ID', 'TRAN_DATE', 'TRAN_AMT', 'ENTERED_BY', 'AUTH_BY', 'AUTH_DATE', 'SOL_ID'],
                ],
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'user_accounts',
                'name' => 'Application user accounts',
                'primary_key_fields' => ['USER_ID'],
                'extraction_config' => [
                    'table' => 'SMTB_USER',
                    'columns' => ['USER_ID', 'USER_NAME', 'USER_STATUS', 'SOL_ID', 'LAST_LOGIN_DATE'],
                ],
                'pii_classification' => 'personal',
                'pii_fields' => ['USER_NAME'],
            ],
            [
                'key' => 'user_roles',
                'name' => 'User role and menu entitlements',
                'description' => 'Feeds the SoD engine: one row per user per entitlement.',
                'primary_key_fields' => ['USER_ID', 'ROLE_ID'],
                'extraction_config' => [
                    'table' => 'SMTB_USER_ROLE',
                    'columns' => ['USER_ID', 'ROLE_ID', 'MENU_ID'],
                ],
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'dormant_accounts',
                'name' => 'Dormant and inactive accounts',
                'primary_key_fields' => ['FORACID'],
                'extraction_config' => [
                    'table' => 'GAM',
                    'columns' => ['FORACID', 'ACCT_CLS_FLG', 'LAST_TRAN_DATE'],
                ],
                'pii_classification' => 'sensitive_personal',
                'pii_fields' => ['FORACID'],
            ],
            [
                'key' => 'limit_overrides',
                'name' => 'Limit and authorisation overrides',
                'primary_key_fields' => ['TRAN_ID', 'OVERRIDE_CODE'],
                'incremental_field' => 'TRAN_DATE',
                'extraction_config' => [
                    'table' => 'OVERRIDE_LOG',
                    'columns' => ['TRAN_ID', 'TRAN_DATE', 'OVERRIDE_CODE', 'OVERRIDE_BY'],
                ],
                'pii_classification' => 'internal',
            ],
        ];
    }
}
