/**
 * SecondLine service worker v2 (Phase 15.1/15.5).
 * Cache-first for the app shell and static assets; network-first for
 * navigations with an offline fallback. Web Push arrives payload-free —
 * the worker fetches the newest unread notification over the
 * authenticated session and shows it, so no notification content ever
 * rests on a push relay. The offline action queue lives in IndexedDB on
 * the page side (resources/js/offline) and syncs on connectivity events.
 */
const CACHE = 'secondline-shell-v2';
const OFFLINE_URL = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL])),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))),
        ).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Static assets: cache-first (Vite fingerprints filenames, so stale
    // entries are impossible — a new build is a new URL).
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/storage/branding/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                        return response;
                    }),
            ),
        );
        return;
    }

    // Page navigations: network-first, offline fallback.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(OFFLINE_URL).then((cached) => cached || Response.error()),
            ),
        );
    }
});

// Payload-free push (15.5): wake, fetch over the session, show.
self.addEventListener('push', (event) => {
    event.waitUntil(
        fetch('/notifications/latest', { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (!data || !data.latest) return;

                return self.registration.showNotification(data.latest.title, {
                    body: data.latest.body,
                    icon: '/icons/icon-192.png',
                    badge: '/icons/icon-192.png',
                    data: { url: data.latest.url },
                    tag: 'secondline-notification',
                });
            })
            .catch(() => {}),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/notifications';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((w) => 'focus' in w);
            if (existing) {
                existing.navigate(url);
                return existing.focus();
            }
            return clients.openWindow(url);
        }),
    );
});
