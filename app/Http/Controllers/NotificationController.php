<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $request->user()
                ->notifications()
                ->paginate(20),
        ]);
    }

    /**
     * The newest unread notification, fetched by the service worker after
     * a payload-free push (15.5) — content travels over the authenticated
     * session, never through the push relay.
     */
    public function latest(Request $request): JsonResponse
    {
        $latest = $request->user()->unreadNotifications()->latest()->first();

        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
            'latest' => $latest ? [
                'title' => $latest->data['title'] ?? config('app.name'),
                'body' => $latest->data['summary'] ?? $latest->data['message'] ?? 'You have a new notification.',
                'url' => $latest->data['url'] ?? route('notifications.index'),
            ] : null,
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
