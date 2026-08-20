import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Warn before navigating away from a dirty form (§12.D.23 / DEF-026).
 *
 * Covers both exits: Inertia client-side visits (confirm dialog) and the
 * browser's own unload — close, refresh, external link (native prompt).
 * Pass `enabled: false` while the form is submitting so a successful
 * save's redirect never triggers the warning.
 */
export default function useUnsavedChanges(isDirty, enabled = true) {
    const active = useRef(isDirty && enabled);
    active.current = isDirty && enabled;

    useEffect(() => {
        const offBefore = router.on('before', (event) => {
            if (!active.current) return;
            if (event.detail.visit.method !== 'get') return; // submissions may leave

            if (!window.confirm('You have unsaved changes. Leave this page and discard them?')) {
                event.preventDefault();
            }
        });

        const onBeforeUnload = (event) => {
            if (!active.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            offBefore();
            window.removeEventListener('beforeunload', onBeforeUnload);
        };
    }, []);
}
