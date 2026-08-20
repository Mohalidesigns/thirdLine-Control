<?php

namespace App\Integrations\Tax;

use App\Integrations\Contracts\Capability;
use App\Integrations\Generic\RestConnector;

/**
 * FIRS Merchant Buyer Solution (12.2, priority 2) — e-invoicing controls
 * under Nigeria's national e-invoicing rollout.
 *
 * UNTESTED TRANSPORT. Endpoint names and payload shapes below are a
 * working model, not a verified contract: FIRS onboarding is per-taxpayer
 * and the specification has moved during rollout. verified = false, and
 * no rule built on this source should be approved before the mapping is
 * checked against the taxpayer's own onboarding pack (R10 in spirit —
 * unverified facts do not drive a regulatory output).
 *
 * connection_config: base_url, tin, business_id
 */
class FirsMbsConnector extends RestConnector
{
    public static function capabilities(): Capability
    {
        return new Capability(
            vendorKey: 'firs_mbs',
            label: 'FIRS Merchant Buyer Solution (e-invoicing)',
            transport: 'rest_api',
            authTypes: ['api_key', 'oauth2'],
            supportsIncremental: true,
            supportsDiscovery: false,
            supportsPagination: true,
            verified: false,
            datasets: ['submitted_invoices', 'rejected_invoices', 'irn_registry'],
            fieldMappings: [
                'submitted_invoices' => [
                    'irn' => 'transaction_id',
                    'invoiceNumber' => 'document_number',
                    'invoiceDate' => 'posted_at',
                    'totalAmount' => 'amount',
                    'validationStatus' => 'status',
                ],
                'rejected_invoices' => [
                    'invoiceNumber' => 'document_number',
                    'rejectionReason' => 'failure_reason',
                    'rejectedAt' => 'detected_at',
                ],
            ],
            notCovered: [
                'Invoice *submission* — this connector reads the outcome, it never files on the taxpayer’s behalf.',
                'VAT return preparation and WHT credit notes.',
                'Sandbox and production endpoints differ; confirm both before go-live.',
            ],
            documentation: 'Complete FIRS MBS onboarding for the TIN, then register read credentials. '
                .'Treat every field as unverified until reconciled with the onboarding pack.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function datasetTemplates(): array
    {
        $endpoint = fn (string $path) => [
            'endpoint' => $path,
            'query' => array_filter([
                'tin' => $this->config('tin'),
                'businessId' => $this->config('business_id'),
            ]),
            'data_path' => 'data',
            'pagination' => ['style' => 'page', 'param' => 'page', 'size_param' => 'size', 'size' => 200],
            'incremental_param' => 'from',
        ];

        return [
            [
                'key' => 'submitted_invoices',
                'name' => 'Submitted invoices and IRNs',
                'primary_key_fields' => ['irn'],
                'incremental_field' => 'invoiceDate',
                'extraction_config' => $endpoint('/api/v1/invoice/list'),
                'pii_classification' => 'personal',
                'pii_fields' => ['buyerTin', 'buyerName'],
            ],
            [
                'key' => 'rejected_invoices',
                'name' => 'Rejected submissions',
                'primary_key_fields' => ['invoiceNumber'],
                'incremental_field' => 'rejectedAt',
                'extraction_config' => $endpoint('/api/v1/invoice/rejected'),
                'pii_classification' => 'personal',
                'pii_fields' => ['buyerTin'],
            ],
            [
                'key' => 'irn_registry',
                'name' => 'IRN registry for completeness testing',
                'primary_key_fields' => ['irn'],
                'incremental_field' => 'issuedAt',
                'extraction_config' => $endpoint('/api/v1/irn/registry'),
                'pii_classification' => 'internal',
            ],
        ];
    }
}
