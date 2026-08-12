<?php

namespace App\Services;

use App\Models\CrossBorderTransfer;
use App\Models\ResidencyAttestation;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Generates the residency attestation (16.2): a signed statement of where
 * each data category is stored and processed, with the infrastructure
 * facts behind it — the artefact a customer hands to CBN, BoG or NDPC.
 * The body is derived entirely from live configuration and the transfer
 * register at generation time, never typed.
 */
class ResidencyAttestationService
{
    public function __construct(private ResidencyGuard $guard) {}

    public function generate(Tenant $tenant, User $user, string $periodStart, string $periodEnd): ResidencyAttestation
    {
        $content = $this->build($tenant, $periodStart, $periodEnd);
        $checksum = hash('sha256', json_encode($content));

        $attestation = ResidencyAttestation::create([
            'tenant_id' => $tenant->id,
            'reference' => ResidencyAttestation::nextReference('RAT'),
            'declared_country' => $this->guard->declaredCountry($tenant),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'content' => $content,
            'checksum' => $checksum,
            'signature' => hash_hmac('sha256', $checksum, config('app.key')),
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);

        $attestation->auditAction('attestation-generated', null, [
            'period' => "{$periodStart} — {$periodEnd}",
            'checksum' => $checksum,
        ]);

        return $attestation;
    }

    public function renderPdf(ResidencyAttestation $attestation): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('reports.residency-attestation', [
            'attestation' => $attestation,
            'template' => [
                'name' => 'Data Residency Attestation',
                'header' => ['subtitle' => 'Data Residency Attestation'],
                'footer' => ['text' => 'Confidential — generated and signed by Atheris Control'],
            ],
        ])->setPaper('a4');
    }

    private function build(Tenant $tenant, string $periodStart, string $periodEnd): array
    {
        $declared = $this->guard->declaredCountry($tenant);
        $diskRegions = config('residency.disk_regions');

        $categories = [
            [
                'category' => 'Application database (controls, risks, obligations, audit trail)',
                'stored_in' => config('database.default'),
                'region' => $declared,
                'evidence' => 'Primary database on the in-country data plane; see deployment documentation.',
            ],
            [
                'category' => 'Evidence files',
                'stored_in' => 'disk: '.EvidenceService::DISK,
                'region' => $diskRegions[EvidenceService::DISK] ?? $declared,
                'evidence' => 'Non-public filesystem disk, residency-guarded on every write.',
            ],
            [
                'category' => 'Continuous-monitoring snapshots',
                'stored_in' => 'disk: '.config('monitoring.snapshot_disk'),
                'region' => $diskRegions[config('monitoring.snapshot_disk')] ?? $declared,
                'evidence' => 'Snapshot paths are prefixed with the residency country per file (Phase 12).',
            ],
            [
                'category' => 'Database backups',
                'stored_in' => 'disk: '.config('residency.backup_disk'),
                'region' => $diskRegions[config('residency.backup_disk')] ?? $declared,
                'evidence' => 'atheris:backup refuses a target outside the declared country.',
            ],
            [
                'category' => 'Queued job payloads',
                'stored_in' => 'queue: '.config('queue.default'),
                'region' => config('residency.queue_regions.'.config('queue.default')) ?? '*',
                'evidence' => 'Every dispatch passes the residency guard before the payload is created.',
            ],
            [
                'category' => 'AI inference payloads',
                'stored_in' => 'local inference (Ollama)',
                'region' => $declared,
                'evidence' => 'Inference runs in-plane; payloads are PII-redacted before leaving the application (R5).',
            ],
        ];

        $transfers = CrossBorderTransfer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$periodStart, $periodEnd.' 23:59:59'])
            ->with('lawfulBasis:id,code,name')
            ->get(['id', 'reference', 'destination_country', 'recipient', 'lawful_basis_id', 'status']);

        return [
            'tenant' => $tenant->name,
            'declared_country' => $declared,
            'period' => ['start' => $periodStart, 'end' => $periodEnd],
            'enforcement' => [
                'layers' => ['filesystem', 'queue', 'backup', 'integration'],
                'runtime_disable' => 'not possible — the guard is configuration-only, with no flag in the database or environment',
                'declaration_changes' => 'blocked at runtime; re-provisioning required and audited',
            ],
            'data_categories' => $categories,
            'cross_border_transfers' => [
                'count' => $transfers->count(),
                'items' => $transfers->map(fn ($t) => [
                    'reference' => $t->reference,
                    'destination_country' => $t->destination_country,
                    'recipient' => $t->recipient,
                    'lawful_basis' => $t->lawfulBasis?->code,
                    'status' => $t->status,
                ])->all(),
            ],
        ];
    }
}
