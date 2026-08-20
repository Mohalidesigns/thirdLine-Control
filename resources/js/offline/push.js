import axios from 'axios';

/**
 * Web Push subscription (15.5). Asks permission, subscribes the service
 * worker's push manager with the installation's VAPID public key, and
 * registers the endpoint server-side. Preference routing (which events
 * arrive by push) stays with the Phase 7 dispatcher.
 */
function base64UrlToUint8Array(base64Url) {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function enablePush(vapidPublicKey) {
    if (!pushSupported() || !vapidPublicKey) return false;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const registration = await navigator.serviceWorker.ready;

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(vapidPublicKey),
        }));

    await axios.post(route('push.subscribe'), subscription.toJSON());

    return true;
}
