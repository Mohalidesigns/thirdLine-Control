import './bootstrap';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { startSyncEngine } from './offline/outbox';
import { configureLocalisation } from './utils';

const appName = import.meta.env.VITE_APP_NAME || 'SecondLine';

function applyBranding(branding) {
    const root = document.documentElement;
    // AEGIS tokens stay the defaults — only override when the tenant set one.
    if (branding?.primary_colour) {
        root.style.setProperty('--color-primary', branding.primary_colour);
    } else {
        root.style.removeProperty('--color-primary');
    }
    if (branding?.accent_colour) {
        root.style.setProperty('--color-accent', branding.accent_colour);
    } else {
        root.style.removeProperty('--color-accent');
    }
    if (branding?.favicon_url) {
        let link = document.querySelector("link[rel='icon']");
        if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            document.head.appendChild(link);
        }
        link.href = branding.favicon_url;
    }
}

let swRegistered = false;

router.on('navigate', (event) => {
    const props = event.detail.page.props;
    configureLocalisation(props.localisation);
    applyBranding(props.branding);

    // Low-bandwidth mode (Phase 7.10): kill animations/transitions globally.
    document.documentElement.classList.toggle('low-bandwidth', !!props.lowBandwidth);

    // PWA shell (Phase 7.10): register once, only when the flag is on.
    if (!swRegistered && (props.features ?? []).includes('pwa') && 'serviceWorker' in navigator) {
        swRegistered = true;
        navigator.serviceWorker.register('/sw.js').catch(() => {});

        // Offline outbox (Phase 15.1): replay queued actions when
        // connectivity returns, refresh the cached working set.
        startSyncEngine();
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#C9A227',
    },
});
