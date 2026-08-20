<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Messaging\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Aggregator delivery receipts (15.4). Normalised shape: message_id,
 * status (delivered|failed), optional cost in minor units + currency.
 * Guarded by a shared token — blank token disables the endpoint.
 */
class SmsReceiptController extends Controller
{
    public function __construct(private SmsService $sms) {}

    public function receive(Request $request): Response
    {
        $token = config('messaging.sms.receipt_token');

        if (! $token || ! hash_equals($token, (string) $request->query('token'))) {
            return response('Forbidden', 403);
        }

        $validated = $request->validate([
            'message_id' => ['required', 'string'],
            'status' => ['required', 'in:delivered,failed'],
            'cost_minor' => ['nullable', 'integer', 'min:0'],
            'cost_currency' => ['nullable', 'string', 'size:3'],
        ]);

        $this->sms->applyReceipt(
            $validated['message_id'],
            $validated['status'],
            $validated['cost_minor'] ?? null,
            $validated['cost_currency'] ?? null,
        );

        return response('OK', 200);
    }
}
