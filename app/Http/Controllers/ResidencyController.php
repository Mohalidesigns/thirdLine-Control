<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrossBorderTransferRequest;
use App\Models\CrossBorderTransfer;
use App\Models\Entity;
use App\Models\ResidencyAttestation;
use App\Models\TransferLawfulBasis;
use App\Services\CrossBorderTransferService;
use App\Services\ResidencyAttestationService;
use App\Services\ResidencyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResidencyController extends Controller
{
    public function __construct(
        private ResidencyGuard $guard,
        private CrossBorderTransferService $transfers,
        private ResidencyAttestationService $attestations,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('view residency'), 403);

        $tenant = $request->user()->tenant;

        return Inertia::render('Residency/Index', [
            'declaration' => [
                'country' => $this->guard->declaredCountry($tenant),
                'disk_regions' => config('residency.disk_regions'),
                'queue_regions' => config('residency.queue_regions'),
                'backup_disk' => config('residency.backup_disk'),
            ],
            'transfers' => CrossBorderTransfer::with([
                'lawfulBasis:id,code,name', 'recorder:id,name', 'authoriser:id,name', 'entity:id,legal_name',
            ])->latest()->paginate(25)->withQueryString(),
            'attestations' => ResidencyAttestation::with('generator:id,name')
                ->latest('generated_at')->paginate(10, pageName: 'attestations')->withQueryString(),
            'lawfulBases' => TransferLawfulBasis::usable()->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'legal_reference', 'verification_status']),
            'entities' => Entity::orderBy('legal_name')->get(['id', 'legal_name']),
            'can' => [
                'record' => $request->user()->can('record transfers'),
                'authorise' => $request->user()->can('authorise transfers'),
                'attest' => $request->user()->can('manage residency'),
            ],
        ]);
    }

    public function storeTransfer(CrossBorderTransferRequest $request): RedirectResponse
    {
        $this->authorize('create', CrossBorderTransfer::class);

        $this->transfers->record($request->validated(), $request->user());

        return back()->with('success', 'Transfer recorded — pending authorisation.');
    }

    public function authoriseTransfer(Request $request, CrossBorderTransfer $transfer): RedirectResponse
    {
        $this->authorize('authorise', $transfer);

        $this->transfers->authorise($transfer, $request->user());

        return back()->with('success', 'Transfer authorised.');
    }

    public function completeTransfer(Request $request, CrossBorderTransfer $transfer): RedirectResponse
    {
        $this->authorize('complete', $transfer);

        $this->transfers->complete($transfer, $request->user());

        return back()->with('success', 'Transfer marked as completed.');
    }

    public function storeAttestation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage residency'), 403);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $this->attestations->generate(
            $request->user()->tenant,
            $request->user(),
            $data['period_start'],
            $data['period_end'],
        );

        return back()->with('success', 'Residency attestation generated and signed.');
    }

    public function downloadAttestation(Request $request, ResidencyAttestation $attestation)
    {
        abort_unless($request->user()->can('view residency'), 403);

        return $this->attestations->renderPdf($attestation)
            ->download($attestation->reference.'-residency-attestation.pdf');
    }
}
