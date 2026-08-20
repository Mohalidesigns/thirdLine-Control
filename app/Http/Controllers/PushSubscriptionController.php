<?php

namespace App\Http\Controllers;

use App\Services\Messaging\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Web Push subscription lifecycle (15.5). */
class PushSubscriptionController extends Controller
{
    public function __construct(private WebPushService $push) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
        ]);

        $this->push->subscribe($request->user(), $validated);

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        $this->push->unsubscribe($request->user(), $validated['endpoint']);

        return response()->json(['subscribed' => false]);
    }
}
