<?php

namespace App\Integrations\CoreBanking;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\SqlConnector;

/**
 * Oracle FLEXCUBE (12.2, priority 2) — roughly 30% of Nigerian
 * deposit-money banks.
 *
 * UNTESTED TRANSPORT. The mapping is written from published FLEXCUBE
 * Universal Banking data-model material. verified = false until a tenant
 * confirms it against their own release; the source page says so plainly.
 *
 * connection_config: driver=oracle, host, port, database (service name),
 *                    schema (defaults to FCUBS)
 */
class FlexcubeConnector extends SqlConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'flexcube',
            label: 'Oracle FLEXCUBE Universal Banking',
            transport: 'database',
            authTypes: ['basic'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['journal_entries', 'smtb_users', 'user_roles', 'maintenance_log', 'suspense_balances'],
            fieldMappings: [
                'journal_entries' => [
                    'TRN_REF_NO' => 'transaction_id',
                    'TRN_DT' => 'posted_at',
                    'LCY_AMOUNT' => 'amount',
                    'MAKER_ID' => 'maker',
                    'CHECKER_ID' => 'checker',
                    'CHECKER_DT_STAMP' => 'authorised_at',
                    'BRANCH_CODE' => 'branch_code',
                ],
                'smtb_users' => [
                    'USER_ID' => 'subject_identifier',
                    'USER_NAME' => 'subject_name',
                    'USER_STATUS' => 'status',
                    'HOME_BRANCH' => 'branch_code',
                ],
                'user_roles' => [
                    'USER_ID' => 'subject_identifier',
                    'ROLE_ID' => 'function',
                ],
            ],
            notCovered: [
                'FLEXCUBE releases differ; column names must be confirmed per installation.',
                'Trade finance, treasury and Islamic modules are not mapped.',
                'FLEXCUBE Direct Banking (channel) data is a separate source.',
            ],
            documentation: 'Read-only Oracle account with SELECT on the FCUBS schema. All mappings unverified.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        return [
            [
                'key' => 'journal_entries',
                'name' => 'Journal entries with maker/checker',
                'primary_key_fields' => ['TRN_REF_NO'],
                'incremental_field' => 'TRN_DT',
                'extraction_config' => [
                    'table' => 'ACVW_ALL_AC_ENTRIES',
                    'columns' => ['TRN_REF_NO', 'TRN_DT', 'LCY_AMOUNT', 'MAKER_ID', 'CHECKER_ID', 'CHECKER_DT_STAMP', 'BRANCH_CODE'],
                ],
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'smtb_users',
                'name' => 'Application users',
                'primary_key_fields' => ['USER_ID'],
                'extraction_config' => [
                    'table' => 'SMTB_USER',
                    'columns' => ['USER_ID', 'USER_NAME', 'USER_STATUS', 'HOME_BRANCH'],
                ],
                'pii_classification' => 'personal',
                'pii_fields' => ['USER_NAME'],
            ],
            [
                'key' => 'user_roles',
                'name' => 'User role entitlements',
                'description' => 'Feeds the SoD engine.',
                'primary_key_fields' => ['USER_ID', 'ROLE_ID'],
                'extraction_config' => ['table' => 'SMTB_USER_ROLE', 'columns' => ['USER_ID', 'ROLE_ID']],
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'maintenance_log',
                'name' => 'Static-data maintenance log',
                'primary_key_fields' => ['MOD_NO', 'RECORD_KEY'],
                'incremental_field' => 'MAKER_DT_STAMP',
                'extraction_config' => [
                    'table' => 'SMTB_MODIFICATION_LOG',
                    'columns' => ['RECORD_KEY', 'MOD_NO', 'MAKER_ID', 'MAKER_DT_STAMP', 'CHECKER_ID', 'CHECKER_DT_STAMP'],
                ],
                'pii_classification' => 'internal',
            ],
            [
                'key' => 'suspense_balances',
                'name' => 'Suspense and sundry account balances',
                'primary_key_fields' => ['GL_CODE', 'BRANCH_CODE'],
                'extraction_config' => [
                    'table' => 'GLTB_GL_BAL',
                    'columns' => ['GL_CODE', 'BRANCH_CODE', 'LCY_BAL', 'BAL_DT'],
                ],
                'pii_classification' => 'none',
            ],
        ];
    }
}
