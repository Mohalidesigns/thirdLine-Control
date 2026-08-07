<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationPreferenceRequest;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        $events = NotificationEvent::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $user->tenant_id))
            ->orderByRaw('tenant_id is null')
            ->orderBy('category')
            ->orderBy('label')
            ->get()
            ->unique('key')
            ->values();

        $preferences = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get(['event_key', 'channel', 'is_enabled', 'digest_frequency', 'quiet_hours_start', 'quiet_hours_end']);

        return Inertia::render('Settings/Notifications', [
            'events' => $events->map(fn ($e) => $e->only([
                'key', 'label', 'description', 'category', 'default_channels', 'is_user_configurable',
            ])),
            'preferences' => $preferences,
            'channels' => NotificationPreference::CHANNELS,
            'digestFrequencies' => NotificationPreference::DIGEST_FREQUENCIES,
        ]);
    }

    public function update(NotificationPreferenceRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->validated('preferences') as $preference) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'event_key' => $preference['event_key'],
                    'channel' => $preference['channel'],
                ],
                [
                    'tenant_id' => $user->tenant_id,
                    'is_enabled' => $preference['is_enabled'],
                    'digest_frequency' => $preference['digest_frequency'],
                    'quiet_hours_start' => $preference['quiet_hours_start'] ?? null,
                    'quiet_hours_end' => $preference['quiet_hours_end'] ?? null,
                ],
            );
        }

        return back()->with('success', 'Notification preferences saved.');
    }
}
