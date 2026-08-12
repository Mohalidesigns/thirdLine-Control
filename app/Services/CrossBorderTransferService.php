<?php

namespace App\Services;

use App\Models\CrossBorderTransfer;
use App\Models\TransferLawfulBasis;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The cross-border transfer register (16.2). A transfer without a usable
 * lawful basis is refused — never recorded, never warned about — and
 * recording and authorising are two people's acts (R2). The register
 * feeds the Phase 13 NDPC submission pack.
 */
class CrossBorderTransferService
{
    public function record(array $data, User $recorder): CrossBorderTransfer
    {
        $basis = TransferLawfulBasis::usable()->find($data['lawful_basis_id'] ?? null);

        if (! $basis) {
            throw ValidationException::withMessages([
                'lawful_basis_id' => 'A cross-border transfer is blocked without a usable lawful basis (NDPA Part VIII).',
            ]);
        }

        $transfer = CrossBorderTransfer::create([
            ...$data,
            'reference' => CrossBorderTransfer::nextReference('CBT'),
            'recorded_by' => $recorder->id,
            'status' => 'pending_authorisation',
        ]);

        $transfer->auditAction('transfer-recorded', null, [
            'destination_country' => $transfer->destination_country,
            'lawful_basis' => $basis->code,
            'recipient' => $transfer->recipient,
        ]);

        return $transfer;
    }

    /** Authorisation is a second person's act — the recorder cannot self-authorise (R2). */
    public function authorise(CrossBorderTransfer $transfer, User $authoriser): CrossBorderTransfer
    {
        if ($transfer->status !== 'pending_authorisation') {
            throw ValidationException::withMessages(['status' => 'Only a pending transfer can be authorised.']);
        }

        if ($transfer->recorded_by === $authoriser->id) {
            throw ValidationException::withMessages([
                'authorised_by' => 'The person who recorded a transfer cannot authorise it.',
            ]);
        }

        $transfer->update([
            'authorised_by' => $authoriser->id,
            'authorised_at' => now(),
            'status' => 'authorised',
        ]);

        $transfer->auditAction('transfer-authorised', null, ['authorised_by' => $authoriser->name]);

        return $transfer;
    }

    public function complete(CrossBorderTransfer $transfer, User $actor): CrossBorderTransfer
    {
        if ($transfer->status !== 'authorised') {
            throw ValidationException::withMessages(['status' => 'A transfer must be authorised before it is executed.']);
        }

        $transfer->update(['status' => 'completed', 'transferred_at' => now()]);

        $transfer->auditAction('transfer-completed', null, ['completed_by' => $actor->name]);

        return $transfer;
    }
}
